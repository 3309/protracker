<?php

namespace App\Support\TradeAssistant;

use App\Exceptions\TradeAssistantException;
use App\Support\InputFormat;
use Illuminate\Support\Facades\Validator;

/**
 * A transaction proposed by the AI Trade Assistant, before review.
 *
 * The AI's answer is untrusted: fromModelOutput() checks its shape and
 * drops any value that is not a well-formed account, ticker, date or
 * number, marking it for review instead. Nothing here saves a record or
 * does arithmetic; the entry forms check and save the reviewed values.
 *
 * Each field has a status: "stated" in the description, "derived" from it
 * (such as "today" turned into a date), "missing", or "ambiguous".
 *
 * @phpstan-type ProposalField array{value: string|null, status: string}
 * @phpstan-type ProposalArray array{action: string, transaction_count: int, unsupported_reason: string|null, fields: array<string, ProposalField>, questions: list<string>}
 */
final readonly class TradeProposal
{
    /**
     * What the description asks for. "not_supported" is a transaction the
     * assistant cannot enter (such as a stock split or a transfer);
     * "unclear" means no transaction could be identified.
     */
    public const array ACTIONS = [
        'buy_stock', 'sell_stock', 'sell_to_open', 'buy_to_open',
        'buy_to_close', 'sell_to_close', 'expire', 'assign', 'roll', 'dividend',
        'not_supported', 'unclear',
    ];

    public const array SUPPORTED_ACTIONS = [
        'buy_stock', 'sell_stock', 'sell_to_open', 'buy_to_open',
        'buy_to_close', 'sell_to_close', 'expire', 'assign', 'roll', 'dividend',
    ];

    /**
     * Actions on an option position that is already recorded, which the
     * tracker finds from the ticker, put or call, strike and expiration.
     */
    public const array POSITION_ACTIONS = ['buy_to_close', 'sell_to_close', 'expire', 'assign', 'roll'];

    public const array STATUSES = ['stated', 'derived', 'missing', 'ambiguous'];

    /**
     * The fields the AI fills in, with the label shown on the review screen.
     */
    public const array FIELDS = [
        'account' => 'Account',
        'symbol' => 'Ticker',
        'option_type' => 'Put or call',
        'trade_date' => 'Trade date',
        'quantity' => 'Shares or contracts',
        'price' => 'Price or premium per share',
        'strike' => 'Strike',
        'expiration' => 'Expiration date',
        'multiplier' => 'Shares per contract',
        'fees' => 'Fees',
        'new_strike' => 'New strike',
        'new_expiration' => 'New expiration date',
        'new_quantity' => 'New contracts',
        'new_price' => 'New premium per share',
        'ex_date' => 'Ex-dividend date',
    ];

    private const string DECIMAL = '/^\d{1,9}(\.\d{1,6})?$/';

    /**
     * @param  array<string, ProposalField>  $fields
     * @param  list<string>  $questions
     */
    public function __construct(
        public string $action,
        public int $transactionCount,
        public ?string $unsupportedReason,
        public array $fields,
        public array $questions,
    ) {}

    /**
     * The JSON schema the AI's answer must follow. Every value is text or
     * null, so amounts are never passed around as binary floating point.
     *
     * @return array<string, mixed>
     */
    public static function schema(): array
    {
        $field = [
            'type' => 'object',
            'properties' => [
                'value' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'status' => ['type' => 'string', 'enum' => self::STATUSES],
            ],
            'required' => ['value', 'status'],
            'additionalProperties' => false,
        ];

        return [
            'type' => 'object',
            'properties' => [
                'transaction_count' => ['type' => 'integer', 'description' => 'How many separate transactions the description contains.'],
                'action' => ['type' => 'string', 'enum' => self::ACTIONS],
                'unsupported_reason' => ['anyOf' => [['type' => 'string'], ['type' => 'null']]],
                'fields' => [
                    'type' => 'object',
                    'properties' => array_fill_keys(array_keys(self::FIELDS), $field),
                    'required' => array_keys(self::FIELDS),
                    'additionalProperties' => false,
                ],
                'questions' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'required' => ['transaction_count', 'action', 'unsupported_reason', 'fields', 'questions'],
            'additionalProperties' => false,
        ];
    }

    /**
     * Check the AI's decoded answer and keep only values in the expected format.
     *
     * @throws TradeAssistantException when the answer does not have the expected shape
     */
    public static function fromModelOutput(mixed $output, TradeContext $context): self
    {
        if (! is_array($output)) {
            throw TradeAssistantException::unreadable();
        }

        $rules = [
            'transaction_count' => ['required', 'integer', 'between:0,100'],
            'action' => ['required', 'string', 'in:'.implode(',', self::ACTIONS)],
            'unsupported_reason' => ['nullable', 'string', 'max:500'],
            'fields' => ['required', 'array'],
            'questions' => ['present', 'array', 'max:10'],
            'questions.*' => ['string', 'max:300'],
        ];

        foreach (array_keys(self::FIELDS) as $name) {
            $rules["fields.{$name}"] = ['required', 'array'];
            $rules["fields.{$name}.value"] = ['present', 'nullable', 'string', 'max:100'];
            $rules["fields.{$name}.status"] = ['required', 'string', 'in:'.implode(',', self::STATUSES)];
        }

        if (Validator::make($output, $rules)->fails()) {
            throw TradeAssistantException::unreadable();
        }

        $questions = array_values($output['questions']);
        $fields = [];

        foreach (self::FIELDS as $name => $label) {
            $raw = $output['fields'][$name]['value'];
            $value = $raw === null ? null : self::clean($name, $raw, $context);
            $status = $output['fields'][$name]['status'];

            if ($raw !== null && $value === null) {
                $status = 'ambiguous';
                $questions[] = sprintf('The %s "%s" could not be read. Please check it.', strtolower($label), mb_substr($raw, 0, 40));
            } elseif ($value === null && $status !== 'ambiguous') {
                $status = 'missing';
            } elseif ($value !== null && $status === 'missing') {
                $status = 'ambiguous';
            }

            $fields[$name] = ['value' => $value, 'status' => $status];
        }

        return new self(
            $output['action'],
            (int) $output['transaction_count'],
            $output['unsupported_reason'],
            $fields,
            $questions,
        );
    }

    /**
     * @param  ProposalArray  $proposal
     */
    public static function fromArray(array $proposal): self
    {
        return new self(
            $proposal['action'],
            $proposal['transaction_count'],
            $proposal['unsupported_reason'],
            $proposal['fields'],
            $proposal['questions'],
        );
    }

    /**
     * @return ProposalArray
     */
    public function toArray(): array
    {
        return [
            'action' => $this->action,
            'transaction_count' => $this->transactionCount,
            'unsupported_reason' => $this->unsupportedReason,
            'fields' => $this->fields,
            'questions' => $this->questions,
        ];
    }

    /**
     * Whether this is a single transaction the assistant can enter.
     */
    public function canBeReviewed(): bool
    {
        return $this->transactionCount === 1 && in_array($this->action, self::SUPPORTED_ACTIONS, true);
    }

    public function value(string $field): string
    {
        return $this->fields[$field]['value'] ?? '';
    }

    public function status(string $field): string
    {
        return $this->fields[$field]['status'] ?? 'missing';
    }

    /**
     * The value in the format the entry forms expect, or null when it is not one.
     */
    private static function clean(string $field, string $raw, TradeContext $context): ?string
    {
        $value = trim($raw);

        return match ($field) {
            'account' => array_key_exists((int) $value, $context->accounts) && ctype_digit($value) ? $value : null,
            'symbol' => preg_match(InputFormat::TICKER, $value) === 1 ? strtoupper($value) : null,
            'option_type' => in_array(strtolower($value), ['put', 'call'], true) ? strtolower($value) : null,
            'trade_date', 'expiration', 'new_expiration', 'ex_date' => self::isDate($value) ? $value : null,
            'multiplier' => preg_match('/^\d{1,6}$/', $value) === 1 ? $value : null,
            default => preg_match(self::DECIMAL, str_replace(['$', ','], '', $value)) === 1 ? str_replace(['$', ','], '', $value) : null,
        };
    }

    private static function isDate(string $value): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $parts) === 1
            && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
    }
}

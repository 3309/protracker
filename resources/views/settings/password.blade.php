<x-layouts::app title="Change password">
    <h1 class="text-2xl font-semibold">Change password</h1>

    @if (session('status') === 'password-updated')
        <div class="status-message">Your password has been changed.</div>
    @endif

    <form method="POST" action="{{ route('user-password.update') }}" class="card flex max-w-md flex-col gap-4">
        @csrf
        @method('PUT')

        <div>
            <label for="current_password" class="form-label">Current password</label>
            <input id="current_password" name="current_password" type="password" required autocomplete="current-password" class="form-input">
            @error('current_password', 'updatePassword')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password" class="form-label">New password</label>
            <input id="password" name="password" type="password" required autocomplete="new-password" class="form-input">
            <p class="form-help">At least 12 characters.</p>
            @error('password', 'updatePassword')
                <p class="form-error">{{ $message }}</p>
            @enderror
        </div>

        <div>
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password" class="form-input">
        </div>

        <div>
            <button type="submit" class="btn-primary">Change password</button>
        </div>
    </form>
</x-layouts::app>

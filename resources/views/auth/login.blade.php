<x-layouts::guest title="Log in">
    <div class="card flex flex-col gap-6">
        <div>
            <h1 class="text-xl font-semibold">{{ config('app.name') }}</h1>
            <p class="text-sm text-zinc-600 dark:text-zinc-400">Log in to continue.</p>
        </div>

        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <div>
                <label for="email" class="form-label">Email address</label>
                <input id="email" name="email" type="email" value="{{ old('email') }}" required autofocus autocomplete="username" class="form-input">
                @error('email')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="password" class="form-label">Password</label>
                <input id="password" name="password" type="password" required autocomplete="current-password" class="form-input">
                @error('password')
                    <p class="form-error">{{ $message }}</p>
                @enderror
            </div>

            <label class="flex items-center gap-2 text-sm">
                <input type="checkbox" name="remember" value="1" @checked(old('remember'))>
                Remember me on this computer
            </label>

            <button type="submit" class="btn-primary">Log in</button>
        </form>

        <p class="text-xs text-zinc-500 dark:text-zinc-400">
            Forgot your password? Run <code>php artisan app:reset-password your@email</code> in the project folder.
        </p>
    </div>
</x-layouts::guest>

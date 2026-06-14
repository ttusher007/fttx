<div>
    <div class="mb-8 text-center">
        <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-2xl bg-indigo-600 text-2xl font-bold text-white shadow-lg">F</div>
        <h1 class="text-2xl font-bold text-white">FTTX<span class="text-indigo-400">.</span>NOC</h1>
        <p class="mt-1 text-sm text-slate-400">OLT Monitoring &amp; Reporting Platform</p>
    </div>

    <div class="rounded-2xl bg-white p-6 shadow-2xl sm:p-8">
        <h2 class="text-lg font-semibold text-slate-900">Sign in to your account</h2>

        <form wire:submit="login" class="mt-6 space-y-4">
            <div>
                <label class="label" for="email">Email</label>
                <input wire:model="email" id="email" type="email" autocomplete="username" class="input" placeholder="you@company.com" autofocus>
                @error('email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div>
                <label class="label" for="password">Password</label>
                <input wire:model="password" id="password" type="password" autocomplete="current-password" class="input" placeholder="••••••••">
                @error('password') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
            </div>

            <div class="flex items-center justify-between">
                <label class="flex items-center gap-2 text-sm text-slate-600">
                    <input wire:model="remember" type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                    Remember me
                </label>
            </div>

            <button type="submit" class="btn-primary w-full" wire:loading.attr="disabled">
                <span wire:loading.remove wire:target="login">Sign in</span>
                <span wire:loading wire:target="login">Signing in…</span>
            </button>
        </form>

        <div class="mt-6 rounded-lg bg-slate-50 p-3 text-xs text-slate-500">
            <p class="font-medium text-slate-600">Demo accounts (password: <code>password</code>)</p>
            <p class="mt-1">admin@fttx.test · noc@fttx.test · staff@fttx.test</p>
        </div>
    </div>
</div>

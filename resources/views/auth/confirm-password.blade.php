<x-guest-layout title="Conferma password — Easy Lab">
    <main class="flex min-h-full flex-col justify-center px-4 py-12 sm:px-6">
        <div class="mx-auto w-full max-w-sm">
            <div class="text-center">
                <h1 class="text-2xl font-bold tracking-tight text-neutral-900">Conferma password</h1>
                <p class="mt-1 text-sm text-neutral-600">Area protetta: conferma la password per continuare.</p>
            </div>

            <div class="mt-8 rounded-lg border border-neutral-200 bg-white p-6 shadow-sm md:p-8">
                @if ($errors->any())
                    <div class="mb-4 rounded-md border border-danger-500/20 bg-danger-100 px-3 py-2 text-sm text-danger-600">
                        {{ $errors->first() }}
                    </div>
                @endif

                <form method="POST" action="{{ route('password.confirm.store') }}" class="space-y-5">
                    @csrf
                    <div>
                        <label for="password" class="block text-sm font-medium text-neutral-800">Password</label>
                        <input id="password" name="password" type="password" autocomplete="current-password" required autofocus
                               class="mt-1 block w-full rounded-md border border-neutral-200 px-3 py-2.5 text-neutral-900 focus:border-primary-600 focus:ring-2 focus:ring-primary-600 focus:outline-none">
                    </div>
                    <button type="submit"
                            class="flex w-full items-center justify-center rounded-md bg-primary-600 px-4 py-2.5 font-medium text-white transition hover:bg-primary-700 focus:ring-2 focus:ring-primary-600 focus:ring-offset-2 focus:outline-none">
                        Conferma
                    </button>
                </form>
            </div>
        </div>
    </main>
</x-guest-layout>

<!DOCTYPE html>
<html lang="id" data-theme="light">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Login - {{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="min-h-screen bg-base-200 flex items-center justify-center p-4">
    <div class="card w-full max-w-md bg-base-100 shadow-xl">
        <div class="card-body">
            <h1 class="text-2xl font-bold text-center mb-1">{{ config('app.name') }}</h1>
            <p class="text-center text-base-content/60 mb-4">Masuk untuk memulai shift</p>

            @if($errors->any())
                <div class="alert alert-error mb-3">{{ $errors->first() }}</div>
            @endif

            <form method="POST" action="{{ route('login.store') }}">
                @csrf
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email') }}" required
                       class="input input-bordered w-full mb-3 h-12" placeholder="admin@lapet.test">

                <label class="block text-sm font-medium mb-1">Password</label>
                <input type="password" name="password" required
                       class="input input-bordered w-full mb-4 h-12" placeholder="••••••••">

                <label class="label cursor-pointer mb-3">
                    <span class="label-text">Ingat saya</span>
                    <input type="checkbox" name="remember" value="1" class="checkbox">
                </label>

                <button type="submit" class="btn btn-primary w-full min-h-[52px] text-lg font-bold">
                    MASUK
                </button>
            </form>
        </div>
    </div>
</body>
</html>

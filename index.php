<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Blue Mogul Client Portal - Access your MSP and fiber services dashboard">
    <title>Login - Blue Mogul Client Portal</title>
    <link rel="icon" type="image/png" href="/client/public/favicon.png">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/css/style.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'blue-mogul': {
                            'primary': '#1a56db',
                            'secondary': '#0d1b3e',
                            'accent': '#3b82f6',
                        }
                    },
                    fontFamily: {
                        'sans': ['Inter', 'system-ui', 'sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(20px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .animate-fadeInUp { animation: fadeInUp 0.5s ease both; }
        .animate-delay-100 { animation-delay: 0.1s; }
        .animate-delay-200 { animation-delay: 0.2s; }
    </style>
</head>
<body class="min-h-screen flex bg-white font-sans">

    <!-- Skip to content link for keyboard accessibility -->
    <a href="#login-form" class="sr-only focus:not-sr-only focus:absolute focus:top-4 focus:left-4 focus:z-50 focus:bg-blue-mogul-primary focus:text-white focus:px-4 focus:py-2 focus:rounded-lg focus:outline-none focus:ring-2 focus:ring-white">
        Skip to login form
    </a>

    <!-- Left panel — login form -->
    <div class="w-full lg:w-1/2 flex flex-col min-h-screen bg-gradient-to-b from-blue-mogul-secondary to-blue-mogul-primary lg:bg-none lg:bg-white">

        <!-- Mobile: top logo bar -->
        <div class="lg:hidden flex items-center justify-center pt-10 pb-4 px-8">
            <img src="/assets/img/logo.png" alt="Blue Mogul" class="h-12 drop-shadow-lg">
        </div>

        <div class="flex-1 flex flex-col items-center justify-center px-8 py-10">
            <div class="w-full max-w-md">

                <!-- Desktop: logo + heading -->
                <div class="hidden lg:block text-center mb-8 animate-fadeInUp">
                    <img src="/assets/img/logo.png" alt="Blue Mogul" class="mx-auto mb-5 h-14">
                    <h1 class="text-3xl font-bold text-slate-950 mb-1" style="color: #020617;">Welcome back</h1>
                    <p class="text-gray-600 text-sm" style="color: #475569;">Sign in to your client portal</p>
                </div>

                <!-- Mobile heading -->
                <div class="lg:hidden text-center mb-8">
                    <h1 class="text-3xl font-bold text-white mb-1">Client Portal</h1>
                    <p class="text-blue-200 text-sm">Unified MSP &amp; Fiber Services</p>
                </div>

                <!-- Card wrapper (desktop only visual card) -->
                <div class="bg-white lg:bg-white rounded-2xl shadow-none lg:shadow-xl p-0 lg:p-8 animate-fadeInUp animate-delay-100">

                    <!-- Error/Success Messages -->
                    <div id="message-container" class="mb-5 hidden">
                        <div class="p-4 rounded-lg" id="message-box">
                            <p id="message-text" role="alert" aria-live="assertive"></p>
                        </div>
                    </div>

                    <!-- Login Form -->
                    <form id="login-form" method="POST" action="/portal/login-handler.php">
                        <?= csrf_field() ?>

                        <div class="mb-5">
                            <label for="email" class="block text-sm font-semibold text-gray-700 lg:text-gray-700 text-white mb-2">
                                <i class="fas fa-envelope mr-2 text-blue-mogul-primary lg:text-blue-mogul-primary text-blue-300"></i>
                                Email or Username
                            </label>
                            <input
                                type="text"
                                id="email"
                                name="email"
                                required
                                data-testid="input-email"
                                aria-label="Email or username"
                                aria-required="true"
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-mogul-primary focus:outline-none focus:ring-2 focus:ring-blue-mogul-primary/30 transition-all text-gray-900 bg-white placeholder-gray-400"
                                placeholder="your.email@example.com"
                                autocomplete="username"
                            >
                        </div>

                        <div class="mb-5">
                            <label for="password" class="block text-sm font-semibold text-gray-700 lg:text-gray-700 text-white mb-2">
                                <i class="fas fa-lock mr-2 text-blue-mogul-primary lg:text-blue-mogul-primary text-blue-300"></i>
                                Password
                            </label>
                            <div class="relative">
                                <input
                                    type="password"
                                    id="password"
                                    name="password"
                                    required
                                    data-testid="input-password"
                                    aria-label="Password"
                                    aria-required="true"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-mogul-primary focus:outline-none focus:ring-2 focus:ring-blue-mogul-primary/30 transition-all text-gray-900 bg-white placeholder-gray-400 pr-12"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    data-testid="button-toggle-password"
                                    aria-label="Toggle password visibility"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between mb-6">
                            <label class="flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                    data-testid="input-remember"
                                    aria-label="Remember me"
                                    class="w-4 h-4 text-blue-mogul-primary border-gray-300 rounded focus:ring-blue-mogul-primary"
                                >
                                <span class="ml-2 text-sm text-gray-600 lg:text-gray-600 text-blue-100">Remember me</span>
                            </label>
                            <a href="/portal/forgot-password.php" class="text-sm text-blue-mogul-primary hover:underline transition-colors">
                                Forgot password?
                            </a>
                        </div>

                        <button
                            type="submit"
                            id="submit-btn"
                            data-testid="button-submit"
                            aria-label="Sign in"
                            class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-xl hover:bg-blue-mogul-accent transition-all duration-300 shadow-lg hover:shadow-xl focus:outline-none focus:ring-2 focus:ring-blue-mogul-primary/50 focus:ring-offset-2"
                        >
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Sign In
                        </button>

                        <button
                            type="button"
                            id="loading-btn"
                            aria-label="Signing in, please wait"
                            class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-xl hidden"
                            disabled
                        >
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Signing in...
                        </button>

                    </form>


                </div>

                <!-- Contact info -->
                <div class="mt-6 text-center animate-fadeInUp animate-delay-200">
                    <p class="text-xs text-gray-400 lg:text-gray-400 text-blue-200 mb-2">Need help?</p>
                    <div class="flex items-center justify-center gap-5">
                        <a href="tel:3463095514" class="text-xs text-gray-500 lg:text-gray-500 text-blue-200 hover:text-blue-mogul-primary transition-colors">
                            <i class="fas fa-phone mr-1"></i>346-309-5514
                        </a>
                        <a href="mailto:contact@bluemogul.biz" class="text-xs text-gray-500 lg:text-gray-500 text-blue-200 hover:text-blue-mogul-primary transition-colors">
                            <i class="fas fa-envelope mr-1"></i>contact@bluemogul.biz
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Right panel — marketing image (hidden on mobile, shown on lg+) -->
    <div class="hidden lg:flex lg:w-1/2 relative flex-col overflow-hidden">
        <img
            src="/assets/img/blue-mogul-banner.png"
            alt="Blue Mogul – High Speed Internet"
            class="absolute inset-0 w-full h-full object-cover"
        >
        <div class="absolute inset-0 bg-blue-mogul-secondary/20"></div>
        <!-- Text overlay for corrected tagline -->
        <div class="absolute bottom-0 left-0 right-0 p-8 bg-blue-mogul-secondary/85">
            <p class="text-white text-sm font-semibold tracking-wider uppercase mb-2">Blue Mogul</p>
            <p class="text-white text-3xl font-extrabold leading-tight mb-3">High Speed Internet</p>
            <p class="text-blue-200 text-sm font-medium tracking-wide italic">Unparalleled coverage and no contractual obligations</p>
        </div>
    </div>

    <!-- Mobile hero image (visible below form, hidden on lg+) -->
    <div class="lg:hidden w-full relative overflow-hidden" style="height: 220px;">
        <img
            src="/assets/img/blue-mogul-banner.png"
            alt="Blue Mogul – High Speed Internet"
            class="absolute inset-0 w-full h-full object-cover"
        >
        <div class="absolute inset-0 bg-blue-mogul-secondary/30"></div>
        <div class="absolute bottom-0 left-0 right-0 p-6 bg-blue-mogul-secondary/85">
            <p class="text-white text-lg font-extrabold leading-tight">High Speed Internet</p>
            <p class="text-blue-200 text-xs font-medium tracking-wide italic">Unparalleled coverage and no contractual obligations</p>
        </div>
    </div>

    <script src="/assets/js/login.js"></script>
    <script>
        const urlParams = new URLSearchParams(window.location.search);
        const message = urlParams.get('message');
        const messageType = urlParams.get('type');
        if (message) {
            showMessage(decodeURIComponent(message), messageType || 'info');
        }
    </script>

</body>
</html>

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
    <link rel="stylesheet" href="/assets/css/brand-design-system.css">
    
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'blue-mogul': {
                            'primary': '#5271FD',
                            'secondary': '#0A0A0A',
                            'accent': '#5271FD',
                            'surface': '#111111',
                            'dim': '#A0A0A0',
                            'border': '#1A1A1A',
                            'panel': '#0A0A0A',
                            'green': '#3ECF8E',
                            'yellow': '#E0A83E',
                            'red': '#E05A4E',
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
<body class="min-h-screen flex bg-[#0A0A0A] text-white font-sans">

    <!-- Skip to content link for keyboard accessibility -->
    <a href="#login-form" class="sr-only focus-visible:not-sr-only focus-visible:absolute focus-visible:top-4 focus-visible:left-4 focus-visible:z-50 focus-visible:bg-[#5271FD] focus-visible:text-white focus-visible:px-4 focus-visible:py-2 focus-visible:rounded-lg focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-white">
        Skip to login form
    </a>

    <!-- Left panel — login form -->
    <div class="w-full lg:w-1/2 flex flex-col min-h-screen bg-gradient-to-b from-blue-mogul-secondary to-blue-mogul-primary lg:bg-none lg:bg-[#0A0A0A]">

        <!-- Mobile: top logo bar -->
        <div class="lg:hidden flex items-center justify-center pt-10 pb-4 px-8">
            <img src="/assets/img/logo.png" alt="Blue Mogul" class="h-12 drop-shadow-lg">
        </div>

        <div class="flex-1 flex flex-col items-center justify-center px-8 py-10">
            <div class="w-full max-w-md">

                <!-- Desktop: logo + heading -->
                <div class="hidden lg:block text-center mb-8 animate-fadeInUp">
                    <img src="/assets/img/logo.png" alt="Blue Mogul" class="mx-auto mb-5 h-14">
                    <h1 class="text-3xl font-bold text-white mb-1">Welcome back</h1>
                    <p class="text-[#A0A0A0] text-sm">Sign in to your client portal</p>
                </div>

                <!-- Mobile heading -->
                <div class="lg:hidden text-center mb-8">
                    <h1 class="text-3xl font-bold text-white mb-1">Client Portal</h1>
                    <p class="text-blue-200 text-sm">Unified MSP &amp; Fiber Services</p>
                </div>

                <!-- Card wrapper (desktop only visual card) -->
                <div class="bg-[#0A0A0A] text-white lg:bg-[#0A0A0A] rounded-2xl shadow-none lg:shadow-xl p-0 lg:p-8 animate-fadeInUp animate-delay-100">

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
                            <label for="email" class="block text-sm font-semibold text-[#A0A0A0] mb-2">
                                <i class="fas fa-envelope mr-2 text-[#5271FD]"></i>
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
                                class="w-full px-4 py-3 border-2 border-[#1A1A1A] rounded-xl focus-visible:border-[#5271FD] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5271FD]/30 transition-all text-white bg-[#0A0A0A] placeholder-[#A0A0A0]"
                                placeholder="your.email@example.com"
                                autocomplete="username"
                            >
                        </div>

                        <div class="mb-5">
                            <label for="password" class="block text-sm font-semibold text-[#A0A0A0] mb-2">
                                <i class="fas fa-lock mr-2 text-[#5271FD]"></i>
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
                                    class="w-full px-4 py-3 border-2 border-[#1A1A1A] rounded-xl focus-visible:border-[#5271FD] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5271FD]/30 transition-all text-white bg-[#0A0A0A] placeholder-[#A0A0A0] pr-12"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    data-testid="button-toggle-password"
                                    aria-label="Toggle password visibility"
                                    class="absolute right-4 top-1/2 -translate-y-1/2 text-[#A0A0A0] hover:text-white transition-colors"
                                >
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center justify-between mb-6">
                            <label for="remember" class="flex items-center cursor-pointer">
                                <input
                                    type="checkbox"
                                    name="remember"
                                    id="remember"
                                    data-testid="input-remember"
                                    aria-label="Remember me"
                                    class="w-4 h-4 text-[#5271FD] border-[#1A1A1A] rounded focus-visible:ring-[#5271FD]"
                                >
                                <span class="ml-2 text-sm text-[#A0A0A0]">Remember me</span>
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
                            class="w-full bg-[#5271FD] text-white font-semibold py-3 rounded-xl hover:bg-[#6B8AFF] transition-all duration-300 shadow-lg hover:shadow-xl focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[#5271FD]/50 focus-visible:ring-offset-2"
                        >
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Sign In
                        </button>

                        <button
                            type="button"
                            id="loading-btn"
                            aria-label="Signing in, please wait"
                            class="w-full bg-[#5271FD] text-white font-semibold py-3 rounded-xl hidden"
                            disabled
                        >
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Signing in...
                        </button>

                    </form>


                </div>

                <!-- Contact info -->
                <div class="mt-6 text-center animate-fadeInUp animate-delay-200">
                    <p class="text-xs text-[#A0A0A0] mb-2">Need help?</p>
                    <div class="flex items-center justify-center gap-5">
                        <a href="tel:3463095514" class="text-xs text-[#A0A0A0] hover:text-[#5271FD] transition-colors">
                            <i class="fas fa-phone mr-1"></i>346-309-5514
                        </a>
                        <a href="mailto:contact@bluemogul.biz" class="text-xs text-[#A0A0A0] hover:text-[#5271FD] transition-colors">
                            <i class="fas fa-envelope mr-1"></i>contact@bluemogul.biz
                        </a>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- Right panel — brand visual (hidden on mobile, shown on lg+) -->
    <div class="hidden lg:flex lg:w-1/2 relative flex-col overflow-hidden bg-gradient-to-br from-[#5271FD] via-[#0A0A0A] to-[#3ECF8E]">
        <!-- Abstract brand pattern -->
        <div class="absolute inset-0 opacity-20">
            <div class="absolute top-1/4 left-1/4 w-64 h-64 bg-[#5271FD] rounded-full blur-3xl"></div>
            <div class="absolute bottom-1/4 right-1/4 w-48 h-48 bg-[#3ECF8E] rounded-full blur-3xl"></div>
            <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-white rounded-full blur-3xl opacity-10"></div>
        </div>
        <!-- Text overlay -->
        <div class="absolute bottom-0 left-0 right-0 p-8 bg-[#0A0A0A]/85">
            <p class="text-white text-sm font-semibold tracking-wider uppercase mb-2">Blue Mogul</p>
            <p class="text-white text-3xl font-extrabold leading-tight mb-3">High Speed Internet</p>
            <p class="text-[#A0A0A0] text-sm font-medium tracking-wide italic">Unparalleled coverage and no contractual obligations</p>
        </div>
    </div>

    <!-- Mobile hero (visible below form, hidden on lg+) -->
    <div class="lg:hidden w-full relative overflow-hidden h-[220px] bg-gradient-to-br from-[#5271FD] via-[#0A0A0A] to-[#3ECF8E]">
        <!-- Abstract brand pattern -->
        <div class="absolute inset-0 opacity-20">
            <div class="absolute top-1/4 left-1/4 w-32 h-32 bg-[#5271FD] rounded-full blur-2xl"></div>
            <div class="absolute bottom-1/4 right-1/4 w-24 h-24 bg-[#3ECF8E] rounded-full blur-2xl"></div>
        </div>
        <div class="absolute bottom-0 left-0 right-0 p-6 bg-[#0A0A0A]/85">
            <p class="text-white text-lg font-extrabold leading-tight">High Speed Internet</p>
            <p class="text-[#A0A0A0] text-xs font-medium tracking-wide italic">Unparalleled coverage and no contractual obligations</p>
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

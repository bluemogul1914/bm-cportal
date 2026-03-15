<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Blue Mogul Client Portal</title>
    
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

    <!-- Left panel — marketing image -->
    <div class="hidden lg:flex lg:w-1/2 xl:w-3/5 relative flex-col overflow-hidden">
        <img
            src="/assets/img/blue-mogul-banner.png"
            alt="Blue Mogul – High Speed Internet"
            class="absolute inset-0 w-full h-full object-cover"
        >
        <!-- subtle dark overlay so text reads cleanly if needed -->
        <div class="absolute inset-0 bg-blue-mogul-secondary/20"></div>

        <!-- bottom badge -->
        <div class="relative mt-auto p-8">
            <div class="bg-white/10 backdrop-blur-sm border border-white/20 rounded-2xl px-6 py-4 inline-block">
                <p class="text-white/70 text-xs uppercase tracking-widest font-semibold mb-0.5">Blue Mogul</p>
                <p class="text-white font-bold text-lg leading-tight">Client Portal</p>
                <p class="text-white/60 text-xs mt-1">Managed Services &amp; High Speed Internet</p>
            </div>
        </div>
    </div>

    <!-- Right panel — login form -->
    <div class="w-full lg:w-1/2 xl:w-2/5 flex flex-col min-h-screen bg-gradient-to-b from-blue-mogul-secondary to-blue-mogul-primary lg:bg-none lg:bg-white">

        <!-- Mobile: top logo bar -->
        <div class="lg:hidden flex items-center justify-center pt-10 pb-4 px-8">
            <img src="/assets/img/logo.png" alt="Blue Mogul" class="h-12 drop-shadow-lg">
        </div>

        <div class="flex-1 flex flex-col items-center justify-center px-8 py-10">
            <div class="w-full max-w-sm">

                <!-- Desktop: logo + heading -->
                <div class="hidden lg:block text-center mb-8 animate-fadeInUp">
                    <img src="/assets/img/logo.png" alt="Blue Mogul" class="mx-auto mb-5 h-14">
                    <h1 class="text-3xl font-bold text-gray-900 mb-1">Welcome back</h1>
                    <p class="text-gray-500 text-sm">Sign in to your client portal</p>
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
                            <p id="message-text"></p>
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
                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-mogul-primary focus:outline-none transition-all text-gray-900 bg-white placeholder-gray-400"
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
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-xl focus:border-blue-mogul-primary focus:outline-none transition-all text-gray-900 bg-white placeholder-gray-400 pr-12"
                                    placeholder="Enter your password"
                                    autocomplete="current-password"
                                >
                                <button
                                    type="button"
                                    id="toggle-password"
                                    data-testid="button-toggle-password"
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
                            class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-xl hover:bg-blue-mogul-accent transition-all duration-300 shadow-lg hover:shadow-xl"
                        >
                            <i class="fas fa-sign-in-alt mr-2"></i>
                            Sign In
                        </button>

                        <button
                            type="button"
                            id="loading-btn"
                            class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-xl hidden"
                            disabled
                        >
                            <i class="fas fa-spinner fa-spin mr-2"></i>
                            Signing in...
                        </button>

                    </form>

                    <!-- Divider -->
                    <div class="relative my-6">
                        <div class="absolute inset-0 flex items-center">
                            <div class="w-full border-t border-gray-200"></div>
                        </div>
                        <div class="relative flex justify-center text-xs">
                            <span class="px-3 bg-white text-gray-400 uppercase tracking-wide">Or access directly</span>
                        </div>
                    </div>

                    <!-- Quick Access -->
                    <div class="grid grid-cols-2 gap-3">
                        <a
                            href="https://itflow.bluemogul.us"
                            target="_blank"
                            data-testid="link-itflow"
                            class="flex items-center justify-center px-3 py-2.5 border-2 border-gray-200 rounded-xl hover:border-blue-mogul-primary hover:bg-blue-50 transition-all group"
                        >
                            <i class="fas fa-ticket-alt mr-2 text-gray-400 group-hover:text-blue-mogul-primary text-sm"></i>
                            <span class="text-xs font-semibold text-gray-600 group-hover:text-blue-mogul-primary">ITFlow Portal</span>
                        </a>
                        <a
                            href="https://uisp.bluemogul.us/crm/client-zone"
                            target="_blank"
                            data-testid="link-uisp"
                            class="flex items-center justify-center px-3 py-2.5 border-2 border-gray-200 rounded-xl hover:border-blue-mogul-primary hover:bg-blue-50 transition-all group"
                        >
                            <i class="fas fa-network-wired mr-2 text-gray-400 group-hover:text-blue-mogul-primary text-sm"></i>
                            <span class="text-xs font-semibold text-gray-600 group-hover:text-blue-mogul-primary">UISP Zone</span>
                        </a>
                    </div>

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

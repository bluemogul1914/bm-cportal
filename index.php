<?php require_once __DIR__ . '/config.php'; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Blue Mogul Client Portal</title>
    
    <!-- Tailwind CSS -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <!-- Font Awesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
    
    <!-- Custom CSS -->
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
</head>
<body class="bg-gradient-to-br from-blue-mogul-secondary via-blue-mogul-primary to-blue-mogul-accent min-h-screen flex items-center justify-center p-4">
    
    <!-- Login Container -->
    <div class="w-full max-w-md">
        
        <!-- Logo & Header -->
        <div class="text-center mb-8 animate-fadeIn">
            <img src="/assets/img/logo.png" alt="Blue Mogul" class="mx-auto mb-6 h-16 drop-shadow-lg">
            <h1 class="text-4xl font-bold text-white mb-2">Client Portal</h1>
            <p class="text-blue-100 text-lg">Unified MSP & Fiber Services</p>
        </div>

        <!-- Login Card -->
        <div class="bg-white rounded-2xl shadow-2xl p-8 mb-6 animate-slideUp">
            
            <!-- Error/Success Messages -->
            <div id="message-container" class="mb-6 hidden">
                <div class="p-4 rounded-lg" id="message-box">
                    <p id="message-text"></p>
                </div>
            </div>

            <!-- Login Form -->
            <form id="login-form" method="POST" action="/portal/login-handler.php">
                            <?= csrf_field() ?>
                
                <!-- Email Field -->
                <div class="mb-6">
                    <label for="email" class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-envelope mr-2 text-blue-mogul-primary"></i>
                        Email or Username
                    </label>
                    <input 
                        type="text" 
                        id="email" 
                        name="email" 
                        required
                        class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-mogul-primary focus:outline-none transition-all duration-300"
                        placeholder="your.email@example.com"
                        autocomplete="username"
                    >
                </div>

                <!-- Password Field -->
                <div class="mb-6">
                    <label for="password" class="block text-gray-700 font-semibold mb-2">
                        <i class="fas fa-lock mr-2 text-blue-mogul-primary"></i>
                        Password
                    </label>
                    <div class="relative">
                        <input 
                            type="password" 
                            id="password" 
                            name="password" 
                            required
                            class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:border-blue-mogul-primary focus:outline-none transition-all duration-300 pr-12"
                            placeholder="Enter your password"
                            autocomplete="current-password"
                        >
                        <button 
                            type="button" 
                            id="toggle-password"
                            class="absolute right-4 top-1/2 transform -translate-y-1/2 text-gray-400 hover:text-gray-600 transition-colors"
                        >
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>

                <!-- Remember Me & Forgot Password -->
                <div class="flex items-center justify-between mb-6">
                    <label class="flex items-center cursor-pointer">
                        <input 
                            type="checkbox" 
                            name="remember" 
                            id="remember"
                            class="w-4 h-4 text-blue-mogul-primary border-gray-300 rounded focus:ring-blue-mogul-primary"
                        >
                        <span class="ml-2 text-gray-700 text-sm">Remember me</span>
                    </label>
                    <a href="/portal/forgot-password.php" class="text-sm text-blue-mogul-primary hover:text-blue-mogul-accent transition-colors">
                        Forgot password?
                    </a>
                </div>

                <!-- Submit Button -->
                <button 
                    type="submit" 
                    id="submit-btn"
                    class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-lg hover:bg-blue-mogul-accent transform hover:scale-105 transition-all duration-300 shadow-lg hover:shadow-xl"
                >
                    <i class="fas fa-sign-in-alt mr-2"></i>
                    Sign In
                </button>

                <!-- Loading State -->
                <button 
                    type="button" 
                    id="loading-btn"
                    class="w-full bg-blue-mogul-primary text-white font-semibold py-3 rounded-lg hidden"
                    disabled
                >
                    <i class="fas fa-spinner fa-spin mr-2"></i>
                    Signing in...
                </button>

            </form>

            <!-- Divider -->
            <div class="relative my-8">
                <div class="absolute inset-0 flex items-center">
                    <div class="w-full border-t border-gray-200"></div>
                </div>
                <div class="relative flex justify-center text-sm">
                    <span class="px-4 bg-white text-gray-500">Or access directly</span>
                </div>
            </div>

            <!-- Quick Access Links -->
            <div class="grid grid-cols-2 gap-4">
                <a 
                    href="https://itflow.bluemogul.us" 
                    target="_blank"
                    class="flex items-center justify-center px-4 py-3 border-2 border-gray-200 rounded-lg hover:border-blue-mogul-primary hover:bg-blue-50 transition-all duration-300 group"
                >
                    <i class="fas fa-ticket-alt mr-2 text-gray-400 group-hover:text-blue-mogul-primary"></i>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-blue-mogul-primary">ITFlow Portal</span>
                </a>
                <a 
                    href="https://uisp.bluemogul.us/crm/client-zone" 
                    target="_blank"
                    class="flex items-center justify-center px-4 py-3 border-2 border-gray-200 rounded-lg hover:border-blue-mogul-primary hover:bg-blue-50 transition-all duration-300 group"
                >
                    <i class="fas fa-network-wired mr-2 text-gray-400 group-hover:text-blue-mogul-primary"></i>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-blue-mogul-primary">UISP Zone</span>
                </a>
            </div>

        </div>

        <!-- Contact Info -->
        <div class="text-center text-white">
            <p class="mb-2">
                <i class="fas fa-question-circle mr-2"></i>
                Need help?
            </p>
            <div class="flex items-center justify-center space-x-6">
                <a href="tel:3463095514" class="hover:text-blue-200 transition-colors">
                    <i class="fas fa-phone mr-2"></i>
                    346-309-5514
                </a>
                <a href="mailto:contact@bluemogul.biz" class="hover:text-blue-200 transition-colors">
                    <i class="fas fa-envelope mr-2"></i>
                    contact@bluemogul.biz
                </a>
            </div>
        </div>

    </div>

    <!-- JavaScript -->
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

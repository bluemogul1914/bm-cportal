/**
 * Blue Mogul Portal - Login Page JavaScript
 * Handles login form, password visibility, and UI interactions
 */

document.addEventListener('DOMContentLoaded', function() {
    
    const loginForm = document.getElementById('login-form');
    const emailInput = document.getElementById('email');
    const passwordInput = document.getElementById('password');
    const togglePasswordBtn = document.getElementById('toggle-password');
    const submitBtn = document.getElementById('submit-btn');
    const loadingBtn = document.getElementById('loading-btn');
    const messageContainer = document.getElementById('message-container');
    const messageBox = document.getElementById('message-box');
    const messageText = document.getElementById('message-text');
    
    if (togglePasswordBtn) {
        togglePasswordBtn.addEventListener('click', function() {
            const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
            passwordInput.setAttribute('type', type);
            
            const icon = this.querySelector('i');
            if (type === 'password') {
                icon.classList.remove('fa-eye-slash');
                icon.classList.add('fa-eye');
            } else {
                icon.classList.remove('fa-eye');
                icon.classList.add('fa-eye-slash');
            }
        });
    }
    
    if (loginForm) {
        loginForm.addEventListener('submit', async function(e) {
            e.preventDefault();
            
            const email = emailInput.value.trim();
            const password = passwordInput.value;
            const remember = document.getElementById('remember').checked;
            
            if (!email || !password) {
                showMessage('Please enter both email and password', 'error');
                return;
            }
            
            setLoadingState(true);
            
            try {
                const csrfInput = loginForm.querySelector('input[name="csrf_token"]');
                const params = new URLSearchParams();
                params.append('email', email);
                params.append('password', password);
                params.append('remember', remember ? '1' : '0');
                if (csrfInput) params.append('csrf_token', csrfInput.value);
                
                const response = await fetch('/portal/login-handler.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: params.toString()
                });
                
                const result = await response.json();
                
                if (result.success) {
                    showMessage(result.message || 'Login successful! Redirecting...', 'success');
                    
                    setTimeout(() => {
                        const redirect = result.redirect || 'dashboard.php';
                        window.location.href = '/portal/' + redirect;
                    }, 1000);
                } else {
                    showMessage(result.message || 'Login failed. Please try again.', 'error');
                    setLoadingState(false);
                }
                
            } catch (error) {
                console.error('Login error:', error);
                showMessage('An error occurred. Please try again.', 'error');
                setLoadingState(false);
            }
        });
    }
    
    if (emailInput) {
        emailInput.addEventListener('blur', function() {
            const email = this.value.trim();
            if (email && !isValidEmail(email)) {
                this.classList.add('border-red-500');
                this.classList.remove('border-gray-200');
            } else {
                this.classList.remove('border-red-500');
                this.classList.add('border-gray-200');
            }
        });
        
        emailInput.addEventListener('focus', function() {
            this.classList.remove('border-red-500');
            this.classList.add('border-blue-mogul-primary');
        });
    }
    
    if (passwordInput) {
        passwordInput.addEventListener('input', function() {
        });
    }
    
    function showMessage(message, type = 'info') {
        if (!messageContainer || !messageBox || !messageText) return;
        
        messageText.textContent = message;
        
        messageBox.className = 'p-4 rounded-lg';
        
        let iconClass = 'fa-info-circle';
        
        switch(type) {
            case 'success':
                messageBox.classList.add('bg-green-50', 'border', 'border-green-200', 'text-green-800');
                iconClass = 'fa-check-circle';
                break;
            case 'error':
                messageBox.classList.add('bg-red-50', 'border', 'border-red-200', 'text-red-800');
                iconClass = 'fa-exclamation-circle';
                break;
            case 'warning':
                messageBox.classList.add('bg-yellow-50', 'border', 'border-yellow-200', 'text-yellow-800');
                iconClass = 'fa-exclamation-triangle';
                break;
            default:
                messageBox.classList.add('bg-blue-50', 'border', 'border-blue-200', 'text-blue-800');
        }
        
        if (!messageText.querySelector('i')) {
            const icon = document.createElement('i');
            icon.className = `fas ${iconClass} mr-2`;
            messageText.prepend(icon);
        }
        
        messageContainer.classList.remove('hidden');
        messageContainer.classList.add('animate-slideDown');
        
        if (type !== 'error') {
            setTimeout(() => {
                hideMessage();
            }, 5000);
        }
    }
    
    function hideMessage() {
        if (messageContainer) {
            messageContainer.classList.add('hidden');
            messageContainer.classList.remove('animate-slideDown');
        }
    }
    
    function setLoadingState(loading) {
        if (loading) {
            submitBtn.classList.add('hidden');
            loadingBtn.classList.remove('hidden');
            emailInput.disabled = true;
            passwordInput.disabled = true;
        } else {
            submitBtn.classList.remove('hidden');
            loadingBtn.classList.add('hidden');
            emailInput.disabled = false;
            passwordInput.disabled = false;
        }
    }
    
    function isValidEmail(email) {
        const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        return re.test(email);
    }
    
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && !e.shiftKey && !e.ctrlKey) {
            const activeElement = document.activeElement;
            if (activeElement === emailInput || activeElement === passwordInput) {
                e.preventDefault();
                loginForm.dispatchEvent(new Event('submit'));
            }
        }
        
        if (e.key === 'Escape') {
            hideMessage();
        }
    });
    
    if (emailInput && !emailInput.value) {
        emailInput.focus();
    }
    
});

window.showMessage = function(message, type = 'info') {
    const messageContainer = document.getElementById('message-container');
    const messageBox = document.getElementById('message-box');
    const messageText = document.getElementById('message-text');
    
    if (!messageContainer || !messageBox || !messageText) return;
    
    messageText.textContent = message;
    messageBox.className = 'p-4 rounded-lg';
    
    let iconClass = 'fa-info-circle';
    
    switch(type) {
        case 'success':
            messageBox.classList.add('bg-green-50', 'border', 'border-green-200', 'text-green-800');
            iconClass = 'fa-check-circle';
            break;
        case 'error':
            messageBox.classList.add('bg-red-50', 'border', 'border-red-200', 'text-red-800');
            iconClass = 'fa-exclamation-circle';
            break;
        case 'warning':
            messageBox.classList.add('bg-yellow-50', 'border', 'border-yellow-200', 'text-yellow-800');
            iconClass = 'fa-exclamation-triangle';
            break;
        default:
            messageBox.classList.add('bg-blue-50', 'border', 'border-blue-200', 'text-blue-800');
    }
    
    const icon = document.createElement('i');
    icon.className = `fas ${iconClass} mr-2`;
    messageText.prepend(icon);
    
    messageContainer.classList.remove('hidden');
    messageContainer.classList.add('animate-slideDown');
    
    if (type !== 'error') {
        setTimeout(() => {
            messageContainer.classList.add('hidden');
        }, 5000);
    }
};

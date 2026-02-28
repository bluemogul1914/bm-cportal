// Dashboard JavaScript

document.addEventListener('DOMContentLoaded', function() {
    console.log('Dashboard loaded');
    loadDashboardData();
    setupEventListeners();
    startAutoRefresh();
});

async function loadDashboardData() {
    try {
        console.log('Loading dashboard data...');
    } catch (error) {
        console.error('Error loading dashboard data:', error);
    }
}

function setupEventListeners() {
    document.addEventListener('click', function(event) {
        if (!event.target.closest('.notification-dropdown')) {
            const notificationsDropdown = document.getElementById('notifications-dropdown');
            if (notificationsDropdown) {
                notificationsDropdown.classList.add('hidden');
            }
        }
        
        if (!event.target.closest('.profile-dropdown')) {
            const profileDropdown = document.getElementById('profile-dropdown');
            if (profileDropdown) {
                profileDropdown.classList.add('hidden');
            }
        }
    });
}

async function payInvoice(invoiceId) {
    try {
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        button.disabled = true;
        
        const response = await fetch('api/create-checkout-session.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                invoice_id: invoiceId
            })
        });
        
        const data = await response.json();
        
        if (data.success) {
            if (data.checkout_url) {
                window.location.href = data.checkout_url;
            } else {
                showNotification('Payment initiated successfully', 'success');
                setTimeout(() => {
                    location.reload();
                }, 2000);
            }
        } else {
            throw new Error(data.message || 'Payment failed');
        }
        
    } catch (error) {
        console.error('Error processing payment:', error);
        showNotification(error.message || 'Failed to process payment', 'error');
        button.innerHTML = originalText;
        button.disabled = false;
    }
}

function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `fixed top-4 right-4 z-50 px-6 py-4 rounded-lg shadow-lg max-w-sm transform transition-all duration-300 translate-x-full`;
    
    const colors = {
        success: 'bg-green-500 text-white',
        error: 'bg-red-500 text-white',
        warning: 'bg-yellow-500 text-white',
        info: 'bg-blue-500 text-white'
    };
    
    notification.className += ` ${colors[type] || colors.info}`;
    
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const icon = icons[type] || icons.info;
    
    notification.innerHTML = `
        <div class="flex items-center">
            <i class="fas ${icon} text-2xl mr-3"></i>
            <p class="font-medium">${message}</p>
            <button onclick="this.parentElement.parentElement.remove()" class="ml-4 text-white hover:text-gray-200">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.classList.remove('translate-x-full');
    }, 100);
    
    setTimeout(() => {
        notification.classList.add('translate-x-full');
        setTimeout(() => {
            notification.remove();
        }, 300);
    }, 5000);
}

function startAutoRefresh() {
    setInterval(() => {
        loadDashboardData();
    }, 30000);
}

function formatCurrency(amount) {
    return new Intl.NumberFormat('en-US', {
        style: 'currency',
        currency: 'USD'
    }).format(amount);
}

function formatDate(dateString) {
    const date = new Date(dateString);
    const now = new Date();
    const diff = now - date;
    const days = Math.floor(diff / (1000 * 60 * 60 * 24));
    
    if (days === 0) {
        return 'Today';
    } else if (days === 1) {
        return 'Yesterday';
    } else if (days < 7) {
        return `${days} days ago`;
    } else {
        return date.toLocaleDateString('en-US', {
            month: 'short',
            day: 'numeric',
            year: 'numeric'
        });
    }
}

function toggleNotifications() {
    const dropdown = document.getElementById('notifications-dropdown');
    const profileDropdown = document.getElementById('profile-dropdown');
    
    if (profileDropdown) {
        profileDropdown.classList.add('hidden');
    }
    
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

function toggleProfile() {
    const dropdown = document.getElementById('profile-dropdown');
    const notificationsDropdown = document.getElementById('notifications-dropdown');
    
    if (notificationsDropdown) {
        notificationsDropdown.classList.add('hidden');
    }
    
    if (dropdown) {
        dropdown.classList.toggle('hidden');
    }
}

function toggleMobileMenu() {
    const menu = document.getElementById('mobile-menu');
    if (menu) {
        menu.classList.toggle('hidden');
    }
}

function setLoadingState(element, loading = true) {
    if (loading) {
        element.classList.add('loading');
    } else {
        element.classList.remove('loading');
    }
}

function confirmAction(message, callback) {
    if (confirm(message)) {
        callback();
    }
}

function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(() => {
        showNotification('Copied to clipboard!', 'success');
    }).catch(err => {
        console.error('Failed to copy:', err);
        showNotification('Failed to copy to clipboard', 'error');
    });
}

function initTooltips() {
}

document.addEventListener('keydown', function(e) {
    if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
        e.preventDefault();
        const searchInput = document.querySelector('input[type="search"]');
        if (searchInput) {
            searchInput.focus();
        }
    }
    
    if (e.key === 'Escape') {
        document.getElementById('notifications-dropdown')?.classList.add('hidden');
        document.getElementById('profile-dropdown')?.classList.add('hidden');
        document.getElementById('mobile-menu')?.classList.add('hidden');
    }
});

if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
    });
}

window.addEventListener('load', () => {
    const perfData = window.performance.timing;
    const pageLoadTime = perfData.loadEventEnd - perfData.navigationStart;
    console.log(`Dashboard loaded in ${pageLoadTime}ms`);
});

window.addEventListener('error', (e) => {
    console.error('Global error:', e.error);
});

window.addEventListener('unhandledrejection', (e) => {
    console.error('Unhandled promise rejection:', e.reason);
});
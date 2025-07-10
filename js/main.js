/**
 * Enhanced Main JavaScript functionality
 * Task Management System
 * Replace: /var/www/tasks/js/main.js
 */

(function() {
    'use strict';
    
    // Global configuration
    window.TaskManagement = {
        apiUrl: '../api/tasks.php',
        debug: true,
        autoRefresh: 300000 // 5 minutes
    };
    
    // Initialize when DOM is ready
    document.addEventListener('DOMContentLoaded', function() {
        initializeApp();
        setupMobileFeatures();
        setupFormValidation();
        setupGlobalErrorHandling();
        
        if (window.TaskManagement.debug) {
            console.log('Task Management System initialized');
        }
    });
    
    // Initialize main application features
    function initializeApp() {
        // Auto-dismiss alerts after 5 seconds
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.classList.contains('alert-dismissible')) {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }
            });
        }, 5000);
        
        // Setup tooltips
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function(tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
        
        // Setup auto-refresh if enabled
        if (window.TaskManagement.autoRefresh && window.location.pathname.includes('dashboard')) {
            setInterval(refreshPageData, window.TaskManagement.autoRefresh);
        }
    }
    
    // Mobile-specific features
    function setupMobileFeatures() {
        if (isMobile()) {
            document.body.classList.add('mobile-device');
            
            // Prevent zoom on input focus for iOS
            const inputs = document.querySelectorAll('input, select, textarea');
            inputs.forEach(function(input) {
                if (input.style.fontSize !== '16px') {
                    input.style.fontSize = '16px';
                }
            });
            
            // Setup touch feedback
            setupTouchFeedback();
        }
    }
    
    // Touch feedback for mobile
    function setupTouchFeedback() {
        document.addEventListener('touchstart', function(e) {
            if (e.target.classList.contains('btn') || 
                e.target.classList.contains('card') ||
                e.target.classList.contains('list-group-item')) {
                e.target.style.transform = 'scale(0.98)';
                e.target.style.transition = 'transform 0.1s ease';
            }
        });
        
        document.addEventListener('touchend', function(e) {
            if (e.target.style.transform) {
                setTimeout(function() {
                    e.target.style.transform = '';
                }, 150);
            }
        });
    }
    
    // Enhanced form validation
    function setupFormValidation() {
        const forms = document.querySelectorAll('.needs-validation');
        
        Array.prototype.slice.call(forms).forEach(function(form) {
            form.addEventListener('submit', function(event) {
                if (!form.checkValidity()) {
                    event.preventDefault();
                    event.stopPropagation();
                    
                    // Show first error
                    const firstInvalid = form.querySelector(':invalid');
                    if (firstInvalid) {
                        firstInvalid.focus();
                        showToast('Please check the form for errors', 'warning');
                    }
                }
                form.classList.add('was-validated');
            }, false);
        });
    }
    
    // Global error handling
    function setupGlobalErrorHandling() {
        window.addEventListener('error', function(e) {
            if (window.TaskManagement.debug) {
                console.error('Global error:', e.error);
            }
            showToast('An unexpected error occurred', 'danger');
        });
        
        window.addEventListener('unhandledrejection', function(e) {
            if (window.TaskManagement.debug) {
                console.error('Unhandled promise rejection:', e.reason);
            }
            showToast('A network error occurred', 'danger');
        });
    }
    
    // Utility functions
    function isMobile() {
        return /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
    }
    
    // Enhanced API call function
    window.apiCall = function(endpoint, options = {}) {
        const defaultOptions = {
            method: 'GET',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            credentials: 'same-origin'
        };
        
        const finalOptions = Object.assign({}, defaultOptions, options);
        
        if (window.TaskManagement.debug) {
            console.log('API Call:', endpoint, finalOptions);
        }
        
        return fetch(endpoint, finalOptions)
            .then(response => {
                if (window.TaskManagement.debug) {
                    console.log('API Response Status:', response.status);
                }
                
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                
                return response.text().then(text => {
                    if (window.TaskManagement.debug) {
                        console.log('API Response Text:', text);
                    }
                    
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('JSON Parse Error:', e);
                        console.error('Response text:', text);
                        throw new Error('Invalid JSON response from server');
                    }
                });
            })
            .then(data => {
                if (window.TaskManagement.debug) {
                    console.log('API Response Data:', data);
                }
                return data;
            })
            .catch(error => {
                if (window.TaskManagement.debug) {
                    console.error('API Error:', error);
                }
                throw error;
            });
    };
    
    // Show loading state
    window.showLoading = function(element) {
        if (element) {
            element.classList.add('loading');
            
            // Add loading overlay for body
            if (element === document.body) {
                const overlay = document.createElement('div');
                overlay.className = 'loading-overlay';
                overlay.style.cssText = `
                    position: fixed;
                    top: 0;
                    left: 0;
                    width: 100%;
                    height: 100%;
                    background: rgba(255, 255, 255, 0.8);
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    z-index: 9999;
                    backdrop-filter: blur(2px);
                `;
                overlay.innerHTML = `
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                `;
                document.body.appendChild(overlay);
            }
        }
    };
    
    // Hide loading state
    window.hideLoading = function(element) {
        if (element) {
            element.classList.remove('loading');
            
            // Remove loading overlay
            const overlay = document.querySelector('.loading-overlay');
            if (overlay) {
                overlay.remove();
            }
        }
    };
    
    // Enhanced toast notification system
    window.showToast = function(message, type = 'info', duration = 5000) {
        // Remove existing toasts
        const existingToasts = document.querySelectorAll('.toast');
        existingToasts.forEach(toast => toast.remove());
        
        // Create toast container if it doesn't exist
        let container = document.querySelector('.toast-container');
        if (!container) {
            container = document.createElement('div');
            container.className = 'toast-container position-fixed top-0 end-0 p-3';
            container.style.zIndex = '1070';
            document.body.appendChild(container);
        }
        
        // Create toast
        const toast = document.createElement('div');
        toast.className = `toast align-items-center text-white bg-${type} border-0`;
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        toast.setAttribute('aria-atomic', 'true');
        
        toast.innerHTML = `
            <div class="d-flex">
                <div class="toast-body">
                    <i class="fas fa-${getToastIcon(type)}"></i> ${message}
                </div>
                <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
            </div>
        `;
        
        container.appendChild(toast);
        
        // Show toast
        const bsToast = new bootstrap.Toast(toast, {
            autohide: true,
            delay: duration
        });
        bsToast.show();
        
        // Remove after hiding
        toast.addEventListener('hidden.bs.toast', function() {
            toast.remove();
        });
        
        // Log toast for debugging
        if (window.TaskManagement.debug) {
            console.log(`Toast: [${type.toUpperCase()}] ${message}`);
        }
    };
    
    function getToastIcon(type) {
        const icons = {
            success: 'check-circle',
            danger: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    // Format time ago
    window.timeAgo = function(date) {
        const now = new Date();
        const diffInSeconds = Math.floor((now - new Date(date)) / 1000);
        
        if (diffInSeconds < 60) return 'just now';
        if (diffInSeconds < 3600) return Math.floor(diffInSeconds / 60) + 'm ago';
        if (diffInSeconds < 86400) return Math.floor(diffInSeconds / 3600) + 'h ago';
        return Math.floor(diffInSeconds / 86400) + 'd ago';
    };
    
    // Format date for datetime-local input
   // Function to format datetime-local input for Riga timezone
function formatDateTimeLocalRiga(date) {
    if (!date) date = new Date();
    
    // Create a date in Riga timezone
    const rigaDate = new Date(date.toLocaleString('en-US', { timeZone: 'Europe/Riga' }));
    
    const year = rigaDate.getFullYear();
    const month = String(rigaDate.getMonth() + 1).padStart(2, '0');
    const day = String(rigaDate.getDate()).padStart(2, '0');
    const hours = String(rigaDate.getHours()).padStart(2, '0');
    const minutes = String(rigaDate.getMinutes()).padStart(2, '0');
    
    return `${year}-${month}-${day}T${hours}:${minutes}`;
}

// Override the formatDateTimeLocal function
window.formatDateTimeLocal = formatDateTimeLocalRiga;
    
    // Refresh page data without full reload
    function refreshPageData() {
        if (window.TaskManagement.debug) {
            console.log('Auto-refreshing page data...');
        }
        
        // Only refresh if user is active (not in background)
        if (!document.hidden) {
            apiCall(window.TaskManagement.apiUrl + '?action=get_tasks&limit=1')
                .then(data => {
                    if (data.success) {
                        // Update any dynamic content here
                        updatePageStats();
                    }
                })
                .catch(error => {
                    console.log('Auto-refresh failed:', error);
                });
        }
    }
    
    // Update page statistics
    function updatePageStats() {
        // This would be implemented per page
        if (typeof updateDashboardStats === 'function') {
            updateDashboardStats();
        }
    }
    
    // Confirmation dialog
    window.confirmAction = function(message, callback) {
        if (confirm(message)) {
            callback();
        }
    };
    
    // Safe element value getter
    window.getValue = function(elementId) {
        const element = document.getElementById(elementId);
        return element ? element.value : '';
    };
    
    // Safe element value setter
    window.setValue = function(elementId, value) {
        const element = document.getElementById(elementId);
        if (element) {
            element.value = value;
        }
    };
    
    // Debounce function for search inputs
    window.debounce = function(func, wait) {
        let timeout;
        return function executedFunction(...args) {
            const later = () => {
                clearTimeout(timeout);
                func(...args);
            };
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
        };
    };
    
    // Initialize page-specific features
    window.initPageFeatures = function() {
        // This can be called from individual pages
        const currentPage = window.location.pathname.split('/').pop();
        
        if (window.TaskManagement.debug) {
            console.log('Initializing features for page:', currentPage);
        }
        
        // Page-specific initialization
        switch (currentPage) {
            case 'dashboard.php':
                initDashboardFeatures();
                break;
            case 'tasks.php':
                initTasksFeatures();
                break;
            case 'team.php':
                initTeamFeatures();
                break;
        }
    };
    
    // Dashboard-specific features
    function initDashboardFeatures() {
        // Auto-refresh dashboard stats
        setInterval(() => {
            if (typeof refreshStats === 'function') {
                refreshStats();
            }
        }, 60000); // Every minute
    }
    
    // Tasks-specific features
    function initTasksFeatures() {
        // Setup task filters
        const filterInputs = document.querySelectorAll('select[name="status"], select[name="priority"]');
        filterInputs.forEach(input => {
            input.addEventListener('change', debounce(() => {
                input.form.submit();
            }, 500));
        });
    }
    
    // Team-specific features
    function initTeamFeatures() {
        // Setup team member interactions
        console.log('Team features initialized');
    }
    
    // Export main functions for debugging
    if (window.TaskManagement.debug) {
        window.TaskManagement.utils = {
            isMobile,
            refreshPageData,
            updatePageStats,
            showToast,
            showLoading,
            hideLoading,
            apiCall,
            timeAgo,
            formatDateTimeLocal,
            debounce
        };
    }
    
})();
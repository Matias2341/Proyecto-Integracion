// Utilidades de autenticación
const API_URL = 'api/auth.php';

// Verificar sesión actual
async function checkSession() {
    try {
        const response = await fetch(`${API_URL}?action=session`, {
            method: 'GET',
            credentials: 'include' // Importante: incluir cookies de sesión
        });
        const result = await response.json();
        return result;
    } catch (error) {
        console.error('Error al verificar sesión:', error);
        return { authenticated: false };
    }
}

// Proteger página - requiere autenticación
async function requireAuth() {
    // Bloquear la página mientras se verifica
    document.body.style.pointerEvents = 'none';
    document.body.style.opacity = '0.5';
    
    const session = await checkSession();
    if (!session.authenticated) {
        // Redirigir inmediatamente
        window.location.replace('login.html');
        return false;
    }
    
    // Restaurar la página si está autenticado
    document.body.style.pointerEvents = 'auto';
    document.body.style.opacity = '1';
    
    return session;
}

// Proteger página inmediatamente (antes de que se cargue el contenido)
async function protectPage() {
    // Verificar inmediatamente sin esperar al DOM
    const session = await checkSession();
    if (!session.authenticated) {
        window.location.replace('login.html');
        return false;
    }
    return session;
}

// Proteger página - requiere rol específico
async function requireRole(allowedRoles) {
    const session = await checkSession();
    if (!session.authenticated) {
        window.location.href = 'login.html';
        return false;
    }
    if (!allowedRoles.includes(session.user.rol)) {
        window.location.href = 'index.html';
        return false;
    }
    return session;
}

// Cerrar sesión
async function logout() {
    try {
        const response = await fetch(`${API_URL}?action=logout`, {
            method: 'GET',
            credentials: 'include' // Importante: incluir cookies de sesión
        });
        const result = await response.json();
        if (result.success) {
            window.location.href = 'login.html';
        }
    } catch (error) {
        console.error('Error al cerrar sesión:', error);
        window.location.href = 'login.html';
    }
}

// Actualizar navegación según el estado de autenticación
async function updateNavigation() {
    const session = await checkSession();
    const navLinks = document.querySelector('.nav-links');
    
    if (!navLinks) return;
    
    // Limpiar enlaces de autenticación existentes
    const authLinks = navLinks.querySelectorAll('.auth-link');
    authLinks.forEach(link => link.remove());
    
    if (session.authenticated) {
        // Usuario autenticado
        // NO agregar enlaces de autenticación al navbar cuando hay sesión
        // El menú de perfil desplegable maneja la información del usuario y cerrar sesión
        // Solo agregar enlaces según el rol si no existen ya en el HTML
        
        const user = session.user;
        const existingNavLinks = navLinks.querySelectorAll('a');
        const hasRoleLink = Array.from(existingNavLinks).some(link => {
            const href = link.getAttribute('href');
            return (user.rol === 'admin' && href === 'admin.html') || 
                   (user.rol === 'medico' && href === 'medico.html');
        });
        
        // NO agregar enlaces si ya existen en el HTML o si hay menú de perfil
        // El menú de perfil se maneja en cada página individualmente
    } else {
        // Usuario no autenticado
        const loginItem = document.createElement('li');
        loginItem.className = 'auth-link';
        loginItem.innerHTML = `<a href="login.html"><i class="fas fa-sign-in-alt"></i> Iniciar Sesión</a>`;
        navLinks.appendChild(loginItem);
        
        const registerItem = document.createElement('li');
        registerItem.className = 'auth-link';
        registerItem.innerHTML = `<a href="registro.html"><i class="fas fa-user-plus"></i> Registrarse</a>`;
        navLinks.appendChild(registerItem);
    }
}

// Hacer logout disponible globalmente
window.logout = logout;

// Actualizar navegación cuando el DOM esté listo
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', updateNavigation);
} else {
    updateNavigation();
}


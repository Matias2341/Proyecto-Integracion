// Funcionalidad compartida para editar perfil de usuario

// Función para abrir el modal de editar perfil
async function openEditProfileModal() {
    try {
        const response = await fetch('api/auth.php?action=session', {
            credentials: 'include'
        });
        const result = await response.json();
        
        if (result.authenticated && result.user) {
            loadUserDataIntoModal(result.user);
            const modal = document.getElementById('editProfileModal');
            if (modal) {
                modal.classList.add('active');
            }
        } else {
            alert('Error al cargar la información del usuario');
        }
    } catch (error) {
        console.error('Error:', error);
        alert('Error al cargar la información del usuario');
    }
}

// Función para cargar datos del usuario en el modal
function loadUserDataIntoModal(user) {
    const nombreInput = document.getElementById('edit-nombre');
    const emailInput = document.getElementById('edit-email');
    const telefonoInput = document.getElementById('edit-telefono');
    const direccionInput = document.getElementById('edit-direccion');
    const rutInput = document.getElementById('edit-rut');
    const fechaInput = document.getElementById('edit-fecha-nacimiento');
    const regionSelect = document.getElementById('edit-region');
    const comunaSelect = document.getElementById('edit-comuna');
    
    if (nombreInput) nombreInput.value = user.nombre || '';
    if (emailInput) emailInput.value = user.email || '';
    if (telefonoInput) telefonoInput.value = user.telefono || '';
    if (direccionInput) direccionInput.value = user.direccion || '';
    if (rutInput) rutInput.value = user.rut || '';
    
    if (fechaInput && user.fecha_nacimiento) {
        const fecha = new Date(user.fecha_nacimiento);
        const fechaFormato = fecha.toISOString().split('T')[0];
        fechaInput.value = fechaFormato;
    }
    
    // Cargar región y comuna si existen
    if (regionSelect && comunaSelect && user.region && typeof regionesChile !== 'undefined') {
        regionSelect.value = user.region;
        comunaSelect.innerHTML = '<option value="">Seleccionar comuna</option>';
        if (regionesChile[user.region]) {
            regionesChile[user.region].sort().forEach(comuna => {
                const option = document.createElement('option');
                option.value = comuna;
                option.textContent = comuna;
                if (user.comuna === comuna) {
                    option.selected = true;
                }
                comunaSelect.appendChild(option);
            });
            comunaSelect.disabled = false;
        }
    }
}

// Función para guardar cambios del perfil
async function saveProfileChanges() {
    const form = document.getElementById('editProfileForm');
    if (!form) return;
    
    const submitBtn = form.querySelector('button[type="submit"]');
    const originalText = submitBtn ? submitBtn.innerHTML : '';
    
    if (submitBtn) {
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Guardando...';
        submitBtn.disabled = true;
    }
    
    const resultDiv = document.getElementById('edit-profile-result');
    if (resultDiv) {
        resultDiv.classList.add('hidden');
        resultDiv.innerHTML = '';
    }
    
    try {
        const formData = {
            nombre: document.getElementById('edit-nombre')?.value.trim() || '',
            email: document.getElementById('edit-email')?.value.trim() || '',
            telefono: document.getElementById('edit-telefono')?.value.trim() || '',
            direccion: document.getElementById('edit-direccion')?.value.trim() || '',
            rut: document.getElementById('edit-rut')?.value.trim() || '',
            fecha_nacimiento: document.getElementById('edit-fecha-nacimiento')?.value || null,
            region: document.getElementById('edit-region')?.value || null,
            comuna: document.getElementById('edit-comuna')?.value || null
        };
        
        if (!formData.nombre || !formData.email) {
            throw new Error('Nombre y email son campos requeridos');
        }
        
        const response = await fetch('api/auth.php?action=update_profile', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            credentials: 'include',
            body: JSON.stringify(formData)
        });
        
        const result = await response.json();
        
        if (result.success) {
            if (resultDiv) {
                resultDiv.className = 'success-message';
                resultDiv.innerHTML = `<p><i class="fas fa-check-circle"></i> ${result.message}</p>`;
                resultDiv.classList.remove('hidden');
            }
            
            // Actualizar información en el menú de perfil
            if (result.user) {
                const userNameEl = document.getElementById('userMenuName');
                const userEmailEl = document.getElementById('userMenuEmail');
                if (userNameEl) userNameEl.textContent = result.user.nombre || 'Usuario';
                if (userEmailEl) userEmailEl.textContent = result.user.email || '';
                
                const avatar = document.querySelector('.user-menu-avatar');
                if (avatar && result.user.nombre) {
                    const initials = result.user.nombre.split(' ').map(n => n[0]).join('').toUpperCase().substring(0, 2);
                    avatar.textContent = initials;
                }
            }
            
            setTimeout(() => {
                const modal = document.getElementById('editProfileModal');
                if (modal) {
                    modal.classList.remove('active');
                }
                if (form) form.reset();
            }, 1500);
        } else {
            if (resultDiv) {
                resultDiv.className = 'error-message';
                resultDiv.innerHTML = `<p><i class="fas fa-exclamation-circle"></i> ${result.message}</p>`;
                resultDiv.classList.remove('hidden');
            }
        }
    } catch (error) {
        if (resultDiv) {
            resultDiv.className = 'error-message';
            resultDiv.innerHTML = `<p><i class="fas fa-exclamation-circle"></i> ${error.message || 'Error al actualizar el perfil'}</p>`;
            resultDiv.classList.remove('hidden');
        }
    } finally {
        if (submitBtn) {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }
    }
}

// Configurar región y comuna en el modal
function setupEditProfileRegionComuna() {
    const editRegion = document.getElementById('edit-region');
    const editComuna = document.getElementById('edit-comuna');
    
    if (editRegion && editComuna && typeof regionesChile !== 'undefined') {
        editRegion.innerHTML = '<option value="">Seleccionar región</option>';
        Object.keys(regionesChile).sort().forEach(region => {
            const option = document.createElement('option');
            option.value = region;
            option.textContent = region;
            editRegion.appendChild(option);
        });
        
        editRegion.addEventListener('change', function() {
            const region = this.value;
            editComuna.innerHTML = '<option value="">Seleccionar comuna</option>';
            
            if (region && regionesChile[region]) {
                regionesChile[region].sort().forEach(comuna => {
                    const option = document.createElement('option');
                    option.value = comuna;
                    option.textContent = comuna;
                    editComuna.appendChild(option);
                });
                editComuna.disabled = false;
            } else {
                editComuna.disabled = true;
            }
        });
    }
}

// Inicializar región y comuna cuando el DOM esté listo
document.addEventListener('DOMContentLoaded', function() {
    if (typeof regionesChile !== 'undefined') {
        setupEditProfileRegionComuna();
    } else {
        setTimeout(() => {
            if (typeof regionesChile !== 'undefined') {
                setupEditProfileRegionComuna();
            }
        }, 500);
    }
    
    // Cerrar modal al hacer clic fuera
    const modal = document.getElementById('editProfileModal');
    if (modal) {
        modal.addEventListener('click', function(e) {
            if (e.target === this) {
                this.classList.remove('active');
            }
        });
    }
});


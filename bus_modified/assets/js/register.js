function initRegister() {
    checkExistingSession();
    setupRegisterForm();
}

function checkExistingSession() {
    if (localStorage.getItem('remember') === 'true') {
        const savedUser = localStorage.getItem('user');
        if (savedUser) {
            try {
                const user = JSON.parse(savedUser);
                redirectByRole(user.role);
            } catch (e) {
                console.error('Erreur parsing user', e);
            }
        }
    }
}

function setupRegisterForm() {
    const form = document.getElementById('registerForm');
    if (!form) return;
    
    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        
        const nom = document.getElementById('nom').value.trim();
        const prenom = document.getElementById('prenom').value.trim();
        const email = document.getElementById('email').value.trim();
        const code = document.getElementById('code').value.trim();
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirmPassword').value;
        
        if (!nom) {
            showPopup('Le nom est requis', 'error');
            document.getElementById('nom').focus();
            return;
        }

        if (!prenom) {
            showPopup('Le prénom est requis', 'error');
            document.getElementById('prenom').focus();
            return;
        }

        if (!email) {
            showPopup('L’email est requis', 'error');
            document.getElementById('email').focus();
            return;
        }

        const emailPattern = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!emailPattern.test(email)) {
            showPopup('Email invalide', 'error');
            document.getElementById('email').focus();
            return;
        }

        if (!code) {
            showPopup('Le code étudiant est requis', 'error');
            document.getElementById('code').focus();
            return;
        }

        if (!/^\d{10}$/.test(code)) {
            showPopup('Code étudiant : 10 chiffres', 'error');
            document.getElementById('code').focus();
            return;
        }

        if (!password) {
            showPopup('Le mot de passe est requis', 'error');
            document.getElementById('password').focus();
            return;
        }

        if (password.length < 6) {
            showPopup('Mot de passe minimum 6 caractères', 'error');
            document.getElementById('password').focus();
            return;
        }

        if (!confirmPassword) {
            showPopup('Confirmez le mot de passe', 'error');
            document.getElementById('confirmPassword').focus();
            return;
        }

        if (password !== confirmPassword) {
            showPopup('Les mots de passe ne correspondent pas', 'error');
            document.getElementById('confirmPassword').focus();
            return;
        }
        
        try {
            const res = await fetch(API_URL + 'register.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ nom, prenom, email, code, password })
            });
            
            const data = await res.json();
            
            if (data.success) {
                showPopup(data.message, 'success');
                setTimeout(() => {
                    window.location.href = 'login.html';
                }, 2000);
            } else {
                showPopup(data.message || 'Erreur d\'inscription', 'error');
            }
        } catch (err) {
            showPopup('Erreur d\'inscription au serveur', 'error');
        }
    });
}

function redirectByRole(role) {
    if (role === 'admin') {
        window.location.href = '../admin/dashboard.html';
    } else if (role === 'chauffeur') {
        window.location.href = '../chauffeur/dashboard.html';
    } else {
        window.location.href = '../etudiant/dashboard.html';
    }
}

document.addEventListener('DOMContentLoaded', initRegister);
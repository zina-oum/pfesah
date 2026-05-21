const I18N_TRANSLATIONS = {
    fr: {
        brand: 'BUS',
        logout: 'Déconnexion',
        back: '← Retour au tableau de bord',
        backBtn: 'Retour',
        dashboardTitle: 'Tableau de Bord',
        searchTitle: 'Rechercher un bus',
        searchPlaceholder: 'Rechercher par numéro, nom, départ, arrivée...',
        mapTitle: 'Carte des bus',
        noResults: 'Aucun bus trouvé pour cette recherche',
        typeToSearch: 'Tapez pour rechercher des bus...',
        login: 'Connexion',
        register: 'Inscription',
        profile: 'Mon Profil',
        report: 'Signalement',
        signupInfo: 'Créez votre compte étudiant',
        loginInfo: 'Accédez à votre espace'
    },
    en: {
        brand: 'BUS',
        logout: 'Logout',
        back: '← Back to dashboard',
        backBtn: 'Back',
        dashboardTitle: 'Dashboard',
        searchTitle: 'Search a bus',
        searchPlaceholder: 'Search by number, name, departure, arrival...',
        mapTitle: 'Bus map',
        noResults: 'No bus found for this search',
        typeToSearch: 'Type to search buses...',
        login: 'Login',
        register: 'Register',
        profile: 'My Profile',
        report: 'Report',
        signupInfo: 'Create your student account',
        loginInfo: 'Access your account'
    }
};

function getPreferredLocale() {
    const language = navigator.language || navigator.userLanguage || 'fr';
    return language.toLowerCase().startsWith('en') ? 'en' : 'fr';
}

function translatePage(locale) {
    const translations = I18N_TRANSLATIONS[locale] || I18N_TRANSLATIONS.fr;
    document.documentElement.lang = locale;
    window.appLocale = locale;
    window.i18n = {
        locale,
        t(key) {
            return translations[key] || I18N_TRANSLATIONS.fr[key] || key;
        }
    };

    document.querySelectorAll('[data-i18n]').forEach(element => {
        const key = element.dataset.i18n;
        const value = translations[key] || element.dataset.defaultText || element.textContent;
        const attr = element.dataset.i18nAttr;

        if (attr === 'placeholder') {
            element.setAttribute('placeholder', value);
        } else if (attr === 'title') {
            element.setAttribute('title', value);
        } else if (attr === 'value') {
            element.value = value;
        } else {
            element.textContent = value;
        }
    });

    const brand = document.querySelector('.brand-text');
    if (brand) {
        brand.textContent = translations.brand;
    }
}

window.addEventListener('DOMContentLoaded', () => {
    translatePage(getPreferredLocale());
});

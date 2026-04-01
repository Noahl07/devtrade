// dev.trade — app.js
import './styles/app.css';

// ── Navbar burger mobile ──
const burger = document.getElementById('navBurger');
const mobileMenu = document.getElementById('navMobile');

if (burger && mobileMenu) {
    burger.addEventListener('click', () => {
        const isOpen = mobileMenu.classList.toggle('is-open');
        burger.setAttribute('aria-expanded', isOpen);
    });

    // Ferme le menu si on clique en dehors
    document.addEventListener('click', (e) => {
        if (!burger.contains(e.target) && !mobileMenu.contains(e.target)) {
            mobileMenu.classList.remove('is-open');
            burger.setAttribute('aria-expanded', false);
        }
    });
}

// ── Flash auto-dismiss après 5s ──
document.querySelectorAll('.flash').forEach(flash => {
    setTimeout(() => {
        flash.style.opacity = '0';
        flash.style.transition = 'opacity 0.4s ease';
        setTimeout(() => flash.remove(), 400);
    }, 5000);
});

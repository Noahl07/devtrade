/**
 * custom-select.js
 * Remplace tous les <select> par un dropdown custom stylé
 * À inclure dans base.html.twig avant </body>
 */

document.addEventListener('DOMContentLoaded', function () {

    function buildCustomSelect(originalSelect) {
        // Ne pas recréer si déjà fait
        if (originalSelect.dataset.customized) return;
        originalSelect.dataset.customized = '1';

        // Wrapper
        const wrapper = document.createElement('div');
        wrapper.className = 'cselect';
        if (originalSelect.className) {
            originalSelect.className.split(' ').forEach(c => {
                if (c && c !== 'form-control') wrapper.classList.add('cselect--' + c);
            });
        }

        // Trigger (bouton affiché)
        const trigger = document.createElement('div');
        trigger.className = 'cselect__trigger';

        const triggerText = document.createElement('span');
        triggerText.className = 'cselect__value';

        const arrow = document.createElement('span');
        arrow.className = 'cselect__arrow';
        arrow.innerHTML = '<i class="bi bi-chevron-down"></i>';

        trigger.appendChild(triggerText);
        trigger.appendChild(arrow);

        // Dropdown list
        const dropdown = document.createElement('ul');
        dropdown.className = 'cselect__dropdown';

        // Remplir les options
        function syncOptions() {
            dropdown.innerHTML = '';
            Array.from(originalSelect.options).forEach((opt) => {
                const li = document.createElement('li');
                li.className = 'cselect__option';
                li.textContent = opt.text;
                li.dataset.value = opt.value;

                if (opt.disabled || opt.value === '') {
                    li.classList.add('cselect__option--placeholder');
                }
                if (opt.selected) {
                    li.classList.add('cselect__option--selected');
                    triggerText.textContent = opt.text;
                    if (opt.value === '') triggerText.classList.add('cselect__value--placeholder');
                    else triggerText.classList.remove('cselect__value--placeholder');
                }

                li.addEventListener('click', function () {
                    if (li.classList.contains('cselect__option--placeholder')) return;

                    // Mettre à jour le select natif
                    originalSelect.value = opt.value;
                    originalSelect.dispatchEvent(new Event('change', { bubbles: true }));

                    // Mettre à jour l'UI
                    dropdown.querySelectorAll('.cselect__option--selected')
                        .forEach(el => el.classList.remove('cselect__option--selected'));
                    li.classList.add('cselect__option--selected');
                    triggerText.textContent = opt.text;
                    triggerText.classList.remove('cselect__value--placeholder');

                    closeDropdown();
                });

                dropdown.appendChild(li);
            });
        }

        syncOptions();

        // Toggle open/close
        function openDropdown() {
            // Fermer tous les autres
            document.querySelectorAll('.cselect--open').forEach(el => {
                if (el !== wrapper) el.classList.remove('cselect--open');
            });
            wrapper.classList.add('cselect--open');
        }

        function closeDropdown() {
            wrapper.classList.remove('cselect--open');
        }

        trigger.addEventListener('click', function (e) {
            e.stopPropagation();
            wrapper.classList.contains('cselect--open') ? closeDropdown() : openDropdown();
        });

        // Fermer en cliquant ailleurs
        document.addEventListener('click', function () { closeDropdown(); });
        dropdown.addEventListener('click', function (e) { e.stopPropagation(); });

        // Keyboard navigation
        trigger.setAttribute('tabindex', '0');
        trigger.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                wrapper.classList.contains('cselect--open') ? closeDropdown() : openDropdown();
            }
            if (e.key === 'Escape') closeDropdown();
        });

        // Assembler et insérer dans le DOM
        wrapper.appendChild(trigger);
        wrapper.appendChild(dropdown);

        // Cacher le select natif mais garder sa valeur dans le formulaire
        originalSelect.style.display = 'none';
        originalSelect.parentNode.insertBefore(wrapper, originalSelect);
        wrapper.appendChild(originalSelect); // garder dans le DOM pour le form submit
    }

    // Appliquer à tous les selects
    document.querySelectorAll('select').forEach(buildCustomSelect);
});
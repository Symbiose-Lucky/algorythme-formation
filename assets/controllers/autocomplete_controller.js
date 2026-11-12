import { Controller } from '@hotwired/stimulus';

/*
 * Liste déroulante avec recherche : posé sur un <select>, il le remplace à
 * l'écran par une zone de saisie qui filtre les options pendant la frappe
 * (sans tenir compte des accents ni de la casse).
 *
 * Le <select> reste dans le formulaire, masqué : c'est lui qui est envoyé.
 * Sans JavaScript, il s'affiche et fonctionne tel quel. Une option à valeur
 * vide (« Aucun domaine ») se choisit en vidant la zone.
 *
 * Accessibilité : motif « combobox » de l'ARIA APG (liste filtrée,
 * aria-activedescendant). Flèches pour parcourir, Entrée pour choisir,
 * Échap pour fermer puis revenir à la valeur choisie.
 *
 *   <select data-controller="autocomplete"
 *           data-autocomplete-vide-value="Aucun domaine ne correspond.">…</select>
 */
export default class extends Controller {
    static values = { vide: { type: String, default: 'Aucun résultat.' } };

    connect() {
        const select = this.element;
        const id = select.id || `autocomplete-${Math.random().toString(36).slice(2)}`;

        this.vide = [...select.options].find((option) => option.value === '') ?? null;
        this.options = [...select.options]
            .filter((option) => option.value !== '')
            .map((option) => ({ valeur: option.value, texte: option.text.trim(), cle: this.normaliser(option.text) }));

        this.zone = document.createElement('div');
        this.zone.className = 'adm-autocomplete';

        this.saisie = document.createElement('input');
        this.saisie.type = 'text';
        this.saisie.id = `${id}_saisie`;
        this.saisie.autocomplete = 'off';
        this.saisie.spellcheck = false;
        this.saisie.placeholder = this.vide ? `${this.vide.text.trim()} — tapez pour chercher` : 'Tapez pour chercher';
        this.saisie.setAttribute('role', 'combobox');
        this.saisie.setAttribute('aria-autocomplete', 'list');
        this.saisie.setAttribute('aria-expanded', 'false');
        this.saisie.setAttribute('aria-controls', `${id}_liste`);
        ['aria-describedby', 'aria-invalid', 'required', 'disabled'].forEach((attribut) => {
            if (select.hasAttribute(attribut)) {
                this.saisie.setAttribute(attribut, select.getAttribute(attribut));
            }
        });

        this.liste = document.createElement('ul');
        this.liste.id = `${id}_liste`;
        this.liste.className = 'adm-autocomplete__liste';
        this.liste.setAttribute('role', 'listbox');
        this.liste.hidden = true;

        this.zone.append(this.saisie, this.liste);
        select.after(this.zone);
        select.hidden = true;
        select.tabIndex = -1;

        // Le libellé désigne désormais la zone de saisie.
        this.libelle = select.id ? document.querySelector(`label[for="${CSS.escape(select.id)}"]`) : null;
        this.libelle?.setAttribute('for', this.saisie.id);
        if (this.libelle) {
            this.libelle.id ||= `${id}_libelle`;
            this.liste.setAttribute('aria-labelledby', this.libelle.id);
        }

        this.ecouteurs = [
            [this.saisie, 'input', () => this.filtrer()],
            [this.saisie, 'focus', () => this.filtrer(true)],
            [this.saisie, 'click', () => this.filtrer(true)],
            [this.saisie, 'keydown', (event) => this.clavier(event)],
            [this.saisie, 'blur', () => this.valider()],
            // mousedown plutôt que click : la zone ne perd pas le focus.
            [this.liste, 'mousedown', (event) => this.cliquer(event)],
        ];
        this.ecouteurs.forEach(([cible, type, rappel]) => cible.addEventListener(type, rappel));

        this.afficherChoix();
    }

    disconnect() {
        // Turbo garde la page en cache : on rend le <select> d'origine.
        this.ecouteurs?.forEach(([cible, type, rappel]) => cible.removeEventListener(type, rappel));
        this.libelle?.setAttribute('for', this.element.id);
        this.zone?.remove();
        this.element.hidden = false;
        this.element.removeAttribute('tabindex');
    }

    /** « Développement » et « developpement » se valent. */
    normaliser(texte) {
        return texte.normalize('NFD').replace(/\p{Diacritic}/gu, '').toLowerCase().trim();
    }

    /** La zone montre l'option choisie dans le <select>. */
    afficherChoix() {
        const choisie = this.options.find((option) => option.valeur === this.element.value);
        this.saisie.value = choisie ? choisie.texte : '';
    }

    /** Affiche les options qui contiennent le texte saisi. Au focus, toutes. */
    filtrer(tout = false) {
        const recherche = tout ? '' : this.normaliser(this.saisie.value);
        this.resultats = this.options.filter((option) => option.cle.includes(recherche));
        this.liste.replaceChildren();

        if (this.resultats.length === 0) {
            const vide = document.createElement('li');
            vide.className = 'adm-autocomplete__vide';
            vide.textContent = this.videValue;
            this.liste.append(vide);
        }

        this.resultats.forEach((option, index) => {
            const element = document.createElement('li');
            element.id = `${this.liste.id}_${index}`;
            element.setAttribute('role', 'option');
            element.dataset.index = String(index);
            element.setAttribute('aria-selected', String(option.valeur === this.element.value));
            element.append(...this.surligner(option.texte, recherche));
            this.liste.append(element);
        });

        // Sur une saisie, la première option correspondante est prête à être choisie.
        const courante = this.resultats.findIndex((option) => option.valeur === this.element.value);
        this.activer(tout ? courante : (recherche === '' ? -1 : 0));
        this.ouvrir();
    }

    /** Met en gras la partie du texte qui correspond à la recherche. */
    surligner(texte, recherche) {
        const debut = recherche === '' ? -1 : this.normaliser(texte).indexOf(recherche);

        if (debut < 0) {
            return [document.createTextNode(texte)];
        }

        // La forme normalisée garde la longueur du texte (accents retirés un à un)
        // pour les lettres latines courantes : les positions coïncident.
        const marque = document.createElement('mark');
        marque.textContent = texte.slice(debut, debut + recherche.length);

        return [
            document.createTextNode(texte.slice(0, debut)),
            marque,
            document.createTextNode(texte.slice(debut + recherche.length)),
        ];
    }

    activer(index) {
        this.active = index;
        [...this.liste.querySelectorAll('[role="option"]')].forEach((element, position) => {
            element.classList.toggle('adm-autocomplete__option--active', position === index);
        });

        const element = index >= 0 ? this.liste.querySelector(`[data-index="${index}"]`) : null;

        if (element) {
            this.saisie.setAttribute('aria-activedescendant', element.id);
            element.scrollIntoView({ block: 'nearest' });
        } else {
            this.saisie.removeAttribute('aria-activedescendant');
        }
    }

    ouvrir() {
        this.liste.hidden = false;
        this.saisie.setAttribute('aria-expanded', 'true');
    }

    fermer() {
        this.liste.hidden = true;
        this.saisie.setAttribute('aria-expanded', 'false');
        this.saisie.removeAttribute('aria-activedescendant');
    }

    clavier(event) {
        const ouverte = !this.liste.hidden;
        const nombre = this.resultats?.length ?? 0;

        switch (event.key) {
            case 'ArrowDown':
                event.preventDefault();
                if (!ouverte) {
                    this.filtrer(true);
                } else if (nombre > 0) {
                    this.activer((this.active + 1) % nombre);
                }
                break;
            case 'ArrowUp':
                event.preventDefault();
                if (ouverte && nombre > 0) {
                    this.activer(this.active <= 0 ? nombre - 1 : this.active - 1);
                }
                break;
            case 'Enter':
                // Liste ouverte : Entrée choisit, sans envoyer le formulaire.
                if (ouverte) {
                    event.preventDefault();
                    if (this.active >= 0) {
                        this.choisir(this.resultats[this.active]);
                    } else {
                        this.valider();
                    }
                }
                break;
            case 'Escape':
                if (ouverte) {
                    event.preventDefault();
                    this.fermer();
                } else {
                    this.afficherChoix();
                }
                break;
        }
    }

    cliquer(event) {
        const element = event.target.closest('[role="option"]');

        if (element) {
            event.preventDefault();
            this.choisir(this.resultats[Number(element.dataset.index)]);
        }
    }

    choisir(option) {
        this.changer(option ? option.valeur : '');
        this.fermer();
    }

    /**
     * En quittant la zone : un texte vide retire le domaine, un nom exact
     * le choisit ; sinon on revient à la valeur choisie.
     */
    valider() {
        const texte = this.normaliser(this.saisie.value);

        if (texte === '' && this.vide) {
            this.changer('');
        } else {
            const exacte = this.options.find((option) => option.cle === texte);
            if (exacte) {
                this.changer(exacte.valeur);
            }
        }

        this.afficherChoix();
        this.fermer();
    }

    changer(valeur) {
        if (this.element.value !== valeur) {
            this.element.value = valeur;
            this.element.dispatchEvent(new Event('change', { bubbles: true }));
        }

        this.afficherChoix();
    }
}

import { Controller } from '@hotwired/stimulus';

/*
 * Recherche et filtre d'un tableau sans bouton : la liste se met à jour
 * pendant la frappe (ou au choix dans une liste déroulante), sans recharger
 * la page ni perdre le curseur.
 *
 * Le formulaire reste un formulaire GET ordinaire : sans JavaScript, son
 * bouton d'envoi fonctionne. Ici, le bouton est masqué et les zones marquées
 * `data-filtre-target="zone"` sont remplacées par celles de la page reçue.
 */
export default class extends Controller {
    static targets = ['formulaire', 'bouton', 'zone'];
    static values = { delai: { type: Number, default: 250 } };

    connect() {
        this.boutonTargets.forEach((bouton) => (bouton.hidden = true));
    }

    disconnect() {
        clearTimeout(this.attente);
        this.requete?.abort();
    }

    /** Pendant la frappe : attend une courte pause avant de chercher. */
    saisir() {
        clearTimeout(this.attente);
        this.attente = setTimeout(() => this.charger(), this.delaiValue);
    }

    /** Liste déroulante, touche Entrée, croix d'effacement : tout de suite. */
    envoyer(event) {
        event.preventDefault();
        clearTimeout(this.attente);
        this.charger();
    }

    /**
     * Envoie le formulaire comme le ferait son bouton : pour une liste
     * déroulante dont le choix change aussi le reste de la page.
     */
    naviguer() {
        this.formulaireTarget.requestSubmit();
    }

    async charger() {
        const formulaire = this.formulaireTarget;
        const parametres = new URLSearchParams(new FormData(formulaire));

        // Un champ vide n'a pas à figurer dans l'adresse.
        [...parametres.entries()]
            .filter(([, valeur]) => valeur === '')
            .forEach(([cle]) => parametres.delete(cle));

        const adresse = new URL(formulaire.action, window.location.href);
        adresse.search = parametres.toString();

        if (adresse.href === this.derniere) {
            return;
        }
        this.derniere = adresse.href;

        this.requete?.abort();
        this.requete = new AbortController();

        try {
            const reponse = await fetch(adresse, {
                headers: { Accept: 'text/html' },
                signal: this.requete.signal,
            });

            if (!reponse.ok) {
                throw new Error(String(reponse.status));
            }

            const page = new DOMParser().parseFromString(await reponse.text(), 'text/html');
            const zones = page.querySelectorAll('[data-filtre-target~="zone"]');

            if (zones.length !== this.zoneTargets.length) {
                throw new Error('zones');
            }

            this.zoneTargets.forEach((zone, rang) => zone.replaceWith(zones[rang]));
            window.history.replaceState(window.history.state, '', adresse);
        } catch (erreur) {
            if (erreur.name !== 'AbortError') {
                // En cas d'échec, on retombe sur la navigation ordinaire.
                window.location.assign(adresse);
            }
        }
    }
}

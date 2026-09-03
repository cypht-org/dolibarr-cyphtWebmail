<?php

/**
 * Translations for the strings this module set renders.
 * @package modules
 * @subpackage dolibarr_context
 */

if (!defined('DEBUG_MODE')) { die(); }

/**
 * @subpackage dolibarr_context/lib
 */
class Hm_Dolibarr_Context_Lang {

    /**
     * @param string|false $lang Interface language code, as Cypht reports it
     * @return array<string,string> Empty when the language is not carried here
     */
    public static function get($lang) {
        if (!is_string($lang) || $lang === '') {
            return array();
        }

        /*  'fr_FR' and the like reduce to the file Cypht would have used. */
        $lang = strtolower(str_replace('-', '_', $lang));
        if (strpos($lang, '_') !== false) {
            $lang = substr($lang, 0, strpos($lang, '_'));
        }

        $all = self::all();

        return array_key_exists($lang, $all) ? $all[$lang] : array();
    }

    /**
     * @return array<string,array<string,string>>
     */
    private static function all() {
        return array(
            'en' => array(
                'Sender in your records' => 'Sender in your records',
                'Nothing open' => 'Nothing open',
                'Checking records...' => 'Checking records...',
                'Records could not be loaded' => 'Records could not be loaded',
                'Check records' => 'Check records',
                'Showing the last known state' => 'Showing the last known state',
                'Open this record' => 'Open this record',
                'See all' => 'See all',
                'Details' => 'Details',
                'Customer' => 'Customer',
                'Supplier' => 'Supplier',
                'Add sender as prospect' => 'Add sender as prospect',
                'No third party or contact has this email address.' => 'No third party or contact has this email address.',
                'Name' => 'Name',
                'Creates a prospect. You can turn it into a customer later.' => 'Creates a prospect. You can turn it into a customer later.',
                'Create prospect' => 'Create prospect',
                'Creating...' => 'Creating...',
                'Prospect created' => 'Prospect created',
                'This sender already has a record' => 'This sender already has a record',
                'Could not add this sender' => 'Could not add this sender',
                'Open the record' => 'Open the record',
            ),
            'fr' => array(
                'Sender in your records' => "L'expéditeur dans vos fiches",
                'Nothing open' => 'Rien en cours',
                'Checking records...' => 'Vérification des fiches...',
                'Records could not be loaded' => 'Impossible de charger les fiches',
                'Check records' => 'Vérifier les fiches',
                'Showing the last known state' => 'Affichage du dernier état connu',
                'Open this record' => 'Ouvrir cette fiche',
                'See all' => 'Tout voir',
                'Details' => 'Détails',
                'Customer' => 'Client',
                'Supplier' => 'Fournisseur',
                'Add sender as prospect' => "Ajouter l'expéditeur en prospect",
                'No third party or contact has this email address.' => "Aucun tiers ni contact ne possède cette adresse e-mail.",
                'Name' => 'Nom',
                'Creates a prospect. You can turn it into a customer later.' => 'Crée un prospect. Vous pourrez le convertir en client plus tard.',
                'Create prospect' => 'Créer le prospect',
                'Creating...' => 'Création...',
                'Prospect created' => 'Prospect créé',
                'This sender already has a record' => 'Cet expéditeur a déjà une fiche',
                'Could not add this sender' => "Impossible d'ajouter cet expéditeur",
                'Open the record' => 'Ouvrir la fiche',
            ),
        );
    }
}

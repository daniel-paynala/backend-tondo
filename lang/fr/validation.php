<?php

/**
 * Messages de validation en français.
 *
 * Laravel est livré en anglais, et ses messages remontent tels quels jusqu'aux
 * formulaires du dashboard : « The numero tel has already been taken. » n'a
 * rien à faire devant un administrateur. Seules les règles réellement
 * utilisées par l'API sont traduites ici ; toute règle absente retombe sur
 * l'anglais, ce qui signale au passage qu'il reste une phrase à écrire.
 */

return [
    'accepted'  => 'Le champ :attribute doit être accepté.',
    'after'     => 'Le champ :attribute doit être une date postérieure au :date.',
    'alpha_num' => 'Le champ :attribute ne peut contenir que des lettres et des chiffres.',
    'array'     => 'Le champ :attribute doit être une liste.',
    'before'    => 'Le champ :attribute doit être une date antérieure au :date.',
    'between'   => [
        'array'   => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file'    => 'Le fichier :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string'  => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean'     => 'Le champ :attribute doit valoir vrai ou faux.',
    'confirmed'   => 'La confirmation du champ :attribute ne correspond pas.',
    'date'        => 'Le champ :attribute n\'est pas une date valide.',
    'date_format' => 'Le champ :attribute ne respecte pas le format :format.',
    'different'   => 'Les champs :attribute et :other doivent être différents.',
    'digits'      => 'Le champ :attribute doit contenir :digits chiffres.',
    'email'       => 'Le champ :attribute doit être une adresse e-mail valide.',
    'exists'      => 'La valeur du champ :attribute n\'existe pas.',
    'file'        => 'Le champ :attribute doit être un fichier.',
    'image'       => 'Le champ :attribute doit être une image.',
    'in'          => 'La valeur du champ :attribute n\'est pas autorisée.',
    'integer'     => 'Le champ :attribute doit être un nombre entier.',
    'max'         => [
        'array'   => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
        'file'    => 'Le fichier :attribute ne peut pas peser plus de :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne peut pas dépasser :max.',
        'string'  => 'Le champ :attribute ne peut pas dépasser :max caractères.',
    ],
    'mimes' => 'Le fichier :attribute doit être de type : :values.',
    'min'   => [
        'array'   => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file'    => 'Le fichier :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir au moins :min.',
        'string'  => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'not_in'   => 'La valeur du champ :attribute n\'est pas autorisée.',
    'numeric'  => 'Le champ :attribute doit être un nombre.',
    'present'  => 'Le champ :attribute doit être présent.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'regex'    => 'Le champ :attribute n\'est pas au bon format.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other vaut :value.',
    'same'     => 'Les champs :attribute et :other doivent être identiques.',
    'size'     => [
        'array'   => 'Le champ :attribute doit contenir :size éléments.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string'  => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'string' => 'Le champ :attribute doit être du texte.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'url'    => 'Le champ :attribute doit être une adresse web valide.',
    'uuid'   => 'Le champ :attribute doit être un identifiant valide.',

    /**
     * Noms affichés à la place des colonnes. Sans cela, le message parle de
     * « numero tel » là où l'écran dit « Numéro Airtel Money ».
     */
    'attributes' => [
        'actif'             => 'statut',
        'categorie_id'      => 'catégorie',
        'contact_email'     => 'e-mail de contact',
        'contact_nom'       => 'nom du contact',
        'contact_tel'       => 'téléphone du contact',
        'description'       => 'description',
        'email'             => 'e-mail',
        'libelle'           => 'libellé',
        'montant'           => 'montant',
        'nom'               => 'nom',
        'notes'             => 'notes',
        'numero_tel'        => 'numéro',
        'password'          => 'mot de passe',
        'prenom'            => 'prénom',
        'role'              => 'rôle',
        'sigle'             => 'sigle',
        'type_paynala'      => 'type de compte',
        'ville'             => 'ville',
    ],
];

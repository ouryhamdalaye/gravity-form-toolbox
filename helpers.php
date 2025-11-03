add_action('wp_enqueue_scripts', 'disable_gf_fields');

function disable_gf_fields () {
  // Pages ciblées (IDs ou slugs)
  $pages = ['inscription-coran-2025-2026', 'inscription-langue-arabe-2025-2026'];

  if (!is_page($pages)) {
    return; // ne charge rien ailleurs
  }

  // Chemins vers le fichier
  $rel  = '/js/gf-disable-choices.js';
  $uri  = get_stylesheet_directory_uri() . $rel;
  $path = get_stylesheet_directory() . $rel;

  // Version = mtime du fichier si dispo, sinon version du thème (fallback)
  $ver = file_exists($path) ? filemtime($path) : wp_get_theme()->get('Version');

  wp_enqueue_script(
    'gf-disable-choices',
    $uri,
    ['jquery'],
    $ver,   // ← version dynamique = bust de cache
    true
  );
};

/**
 * Gravity Forms – Safeguard serveur (validation)
 * - Multi-formulaires, multi-champs
 * - Types supportés : checkbox, select, radio
 * - Refuse la soumission si une valeur bloquée est présente
 *
 * Config : $GLOBALS['GF_SAFEGUARD_RULES']
 *  form_id => [
 *    field_id => [
 *      'type'    => 'checkbox'|'select'|'radio',
 *      'blocked' => ['Value 1', 'Value 2', ...],
 *      'message' => 'Cette option est indisponible.' // (optionnel)
 *    ],
 *  ]
 */

add_action('init', function () {
  $GLOBALS['GF_SAFEGUARD_RULES'] = [
    7 => [
      21 => [
        'type'    => 'select',
        'blocked' => [
          'intensive',
        ],
        'message' => 'Cette formule n’est pas disponible.'
      ],
    ],
	10 => [
      28 => [
        'type'    => 'select',
        'blocked' => [
          'L2',
		  'L3',
        ],
        'message' => 'Cette année n’est pas disponible.'
      ],
    ],
    // …ajoute d’autres formulaires/champs ici
  ];
});

add_filter('gform_validation', 'block_gf_fields', 10);

function block_gf_fields($result){
  $form   = $result['form'];
  $formId = (int) $form['id'];
  $rules  = $GLOBALS['GF_SAFEGUARD_RULES'][$formId] ?? null;
  if (!$rules) return $result;

  // util de normalisation
  $norm = static function($v){ return trim((string)$v); };

  foreach ($form['fields'] as &$field) {
    $fid = (int) $field->id;
    if (empty($rules[$fid])) continue;

    $type    = strtolower($rules[$fid]['type'] ?? '');
    $blocked = array_map($norm, (array)($rules[$fid]['blocked'] ?? []));
    $msg     = $rules[$fid]['message'] ?? 'Cette option est indisponible.';

    if (!$blocked || !in_array($type, ['checkbox','select','radio'], true)) continue;

    $selected = [];

    if ($type === 'checkbox') {
      // Checkbox GF poste des clés input_{fid}.{index} (dot) ou parfois input_{fid}_{index} (underscore)
      if (!empty($field->inputs) && is_array($field->inputs)) {
        foreach ($field->inputs as $input) {
          if (empty($input['id'])) continue;
          $subId  = (string) $input['id'];           // ex "28.1"
          $kDot   = 'input_'.$subId;                 // "input_28.1"
          $kUnd   = 'input_'.str_replace('.','_',$subId); // "input_28_1"
          if (isset($_POST[$kDot])) $selected[] = sanitize_text_field(wp_unslash($_POST[$kDot]));
          elseif (isset($_POST[$kUnd])) $selected[] = sanitize_text_field(wp_unslash($_POST[$kUnd]));
        }
      } else {
        // fallback : scan des POST keys
        foreach ($_POST as $k => $v) {
          if (!is_string($k)) continue;
          if (preg_match('/^input_'.$fid.'(\.|_)\d+$/', $k)) {
            $selected[] = sanitize_text_field(wp_unslash($v));
          }
        }
      }
    } elseif ($type === 'select' || $type === 'radio') {
      // Select & Radio postent sous input_{fid}
      $k = 'input_'.$fid;
      if (isset($_POST[$k])) {
        $val = $_POST[$k];
        if (is_array($val)) {
          foreach ($val as $v) $selected[] = sanitize_text_field(wp_unslash($v));
        } else {
          $selected[] = sanitize_text_field(wp_unslash($val));
        }
      }
    }

    // Intersection bloquée ?
    $selectedNorm = array_map($norm, $selected);
    if (array_intersect($blocked, $selectedNorm)) {
      $field->failed_validation  = true;
      $field->validation_message = $msg;
      $result['is_valid']        = false;
    }
  }

  $result['form'] = $form;
  return $result;
};

/**
 * Gravity Forms – "Au moins une case cochée" par groupe de champs Checkbox.
 * - S'applique au submit final (target_page=0), pour éviter les faux positifs de visibilité.
 * - Ignore la visibilité à ce moment-là (on veut une règle globale).
 * - Multi-formulaires et multi-groupes possibles.
 *
 * CONFIGS ACCEPTÉES :
 * 1) Simple :  { formId: [fieldId, fieldId, ...] }  -> 1 groupe unique avec message par défaut
 * 2) Avancé :  {
 *        formId: {
 *            message: "Message par défaut pour ce formulaire",
 *            groups: [
 *               [fieldId, fieldId],                                    // groupe simple
 *               { fields: [fieldId, fieldId], message: "Msg spécifique"} // groupe + message spécifique
 *            ],
 *            ignore_if_field: [fieldId, 'value_that_stops_check']     // NOUVEAU: ignorer la validation si un champ a une valeur spécifique
 *        }
 *    }
 *
 * NOUVELLE FONCTIONNALITÉ - ignore_if_field :
 * Permet d'ignorer complètement la validation des groupes de checkboxes si un champ
 * spécifique (ex: radio button) a une valeur donnée. Utile pour des formulaires
 * conditionnels où certaines sections ne doivent pas être validées selon le contexte.
 * 
 * Exemple d'usage : Si un radio button "Type de demande" = "Urgent", alors on n'exige
 * pas de sélection dans les groupes de matières optionnelles.
 *
 * Exemples de config en bas.
 */
add_filter( 'gform_validation', 'gform_require_at_least_one_checkbox_in_groups', 50 );

function gform_require_at_least_one_checkbox_in_groups( $validation_result ) {
	 // =======================
    // === CONFIG ICI :)  ====
    // =======================

    $CONFIG = [
        // --- Format simple (un seul groupe) ---
        //17 => [33, 34], // Form #17 : groupe {33,34}

        // --- Format avancé (plusieurs groupes + messages) ---
        9 => [
            'message' => "Choisissez au moins une option parmi les matières indiquées.",
            'groups'  => [
                [33, 34], // Groupe 1
                //['fields' => [22, 23], 'message' => "Au moins un choix (Plat/Dessert)."], // Groupe 2 custom msg
            ],
            // Optionnel: ignorer la validation si un champ spécifique a une valeur donnée
            // Format: 'ignore_if_field' => ['field_id', 'value_that_stops_check']
            'ignore_if_field' => [26, "Complète"],
        ],
    ];

    // Message par défaut si non fourni
    $DEFAULT_MESSAGE = "Veuillez sélectionner au moins une option.";

    // (Optionnel) activer les logs
    $LOG = false;

    // =======================
    // === CORE LOGIQUE   ====
    // =======================

    $form = $validation_result['form'];
    $form_id = isset($form['id']) ? (int)$form['id'] : 0;

    if ($form_id <= 0) {
        return $validation_result;
    }

    // Si le form n'est pas configuré → on sort
    if (!array_key_exists($form_id, $CONFIG)) {
        return $validation_result;
    }

    $fid = $form_id;
    $src_page = (int) rgpost("gform_source_page_number_{$fid}");
    $tgt_page = (int) rgpost("gform_target_page_number_{$fid}");
    $is_final_submit = ($tgt_page === 0);

    if ($LOG) error_log("[GF MAP] form={$form_id} src={$src_page} tgt={$tgt_page} final=".($is_final_submit?'yes':'no'));

    // On n’applique qu’au submit final
    if (!$is_final_submit) {
        return $validation_result;
    }

    // Normaliser la config de ce formulaire en liste de groupes uniformes
    // Sortie attendue: liste d'items = [ 'fields' => [ids...], 'message' => '...' ]
    $normalize = function ($config_for_form) use ($DEFAULT_MESSAGE) {
        $groups = [];

        // Format simple : [33,34]
        if (is_array($config_for_form) && isset($config_for_form[0]) && is_int($config_for_form[0])) {
            $groups[] = [
                'fields'  => array_values(array_map('intval', $config_for_form)),
                'message' => $DEFAULT_MESSAGE,
            ];
            return $groups;
        }

        // Format avancé : ['message'=>..., 'groups'=> [...]]
        if (is_array($config_for_form) && isset($config_for_form['groups']) && is_array($config_for_form['groups'])) {
            $default_msg = !empty($config_for_form['message']) ? (string)$config_for_form['message'] : $DEFAULT_MESSAGE;

            foreach ($config_for_form['groups'] as $g) {
                if (is_array($g)) {
                    // groupe simple : [ids...]
                    if (isset($g[0]) && is_int($g[0])) {
                        $groups[] = [
                            'fields'  => array_values(array_map('intval', $g)),
                            'message' => $default_msg,
                        ];
                        continue;
                    }
                    // groupe objet : ['fields'=>[...], 'message'=>'...']
                    if (isset($g['fields'])) {
                        $msg = !empty($g['message']) ? (string)$g['message'] : $default_msg;
                        $groups[] = [
                            'fields'  => array_values(array_map('intval', (array)$g['fields'])),
                            'message' => $msg,
                        ];
                    }
                }
            }
        }

        return $groups;
    };

    $groups = $normalize($CONFIG[$form_id]);

    if (empty($groups)) {
        // Rien à valider pour ce form
        return $validation_result;
    }

    // =======================
    // === IGNORE FIELD CHECK ===
    // =======================
    // Vérifier si on doit ignorer la validation basée sur un champ spécifique
    $config_for_form = $CONFIG[$form_id];
    if (is_array($config_for_form) && isset($config_for_form['ignore_if_field']) && is_array($config_for_form['ignore_if_field'])) {
        $ignore_config = $config_for_form['ignore_if_field'];
        
        // Format attendu: ['field_id', 'value_that_stops_check']
        if (count($ignore_config) >= 2) {
            $ignore_field_id = (int)$ignore_config[0];
            $ignore_value = (string)$ignore_config[1];
            
            // Récupérer la valeur du champ depuis $_POST
            $field_value = rgpost("input_{$ignore_field_id}");
            
            if ($LOG) {
                error_log("[GF MAP] ignore check: field_id={$ignore_field_id}, expected_value='{$ignore_value}', actual_value='{$field_value}'");
            }
            
            // Si la valeur du champ correspond à celle qui doit ignorer la validation
            if ($field_value === $ignore_value) {
                if ($LOG) {
                    error_log("[GF MAP] validation ignored due to field {$ignore_field_id} = '{$ignore_value}'");
                }
                // Retourner sans validation - on ignore complètement le check
                return $validation_result;
            }
        }
    }

    // Indexer les champs pour pouvoir marquer les erreurs
    $field_index_by_id = [];
    foreach ($form['fields'] as $idx => $f) {
        if (is_object($f)) {
            $field_index_by_id[(int)$f->id] = $idx;
        }
    }

    // Helper : présence d’au moins une clé POST "input_{fid}_X"
    $has_any_posted_choice = function ($fid) {
        $prefix = 'input_' . (int)$fid . '_';
        foreach ($_POST as $k => $v) {
            if (strpos($k, $prefix) === 0 && $v !== '' && $v !== null) {
                return true;
            }
        }
        return false;
    };

    $form_failed = false;

    // On évalue chaque groupe indépendamment
    foreach ($groups as $i => $group) {
        $field_ids = array_unique(array_filter((array)$group['fields'], 'is_int'));
        $message   = !empty($group['message']) ? $group['message'] : $DEFAULT_MESSAGE;

        if (empty($field_ids)) {
            continue;
        }

        // Détection : au moins un champ du groupe a une case cochée ?
        $any_checked = false;
        foreach ($field_ids as $gid) {
            if ($has_any_posted_choice($gid)) {
                $any_checked = true;
                break;
            }
        }

        if ($LOG) {
            $debug = [];
            foreach ($field_ids as $gid) {
                $matches = [];
                foreach (array_keys($_POST) as $k) {
                    if (strpos($k, 'input_'.$gid.'_') === 0) $matches[] = $k;
                }
                $debug[$gid] = $matches;
            }
            error_log("[GF MAP] group#{$i} any_checked=".($any_checked?'yes':'no')." matches=".json_encode($debug));
        }

        if (!$any_checked) {
            $form_failed = true;
            // Marquer tous les champs du groupe (s’ils existent dans ce form)
            foreach ($field_ids as $gid) {
                $idx = $field_index_by_id[$gid] ?? null;
                if ($idx === null) {
                    if ($LOG) error_log("[GF MAP] field #{$gid} absent dans le form {$form_id} (non marqué).");
                    continue;
                }
                /** @var GF_Field $field */
                $field = $form['fields'][$idx];
                $field->failed_validation  = true;
                $field->validation_message = $message;
                $form['fields'][$idx]      = $field; // réécrit dans le form
            }
        }
    }

    if ($form_failed) {
        $validation_result['is_valid'] = false;
        $validation_result['form']     = $form;
    }

    return $validation_result;
}
/**
 * Gravity Forms – Correct checkbox labels in EMAILS while keeping duplicate values.
 * Adds two custom merge tags you can use only in notifications:
 *   - {checkbox_labels:FIELD_ID}
 *   - {all_fields_by_input}
 */

add_filter( 'gform_custom_merge_tags', function ( $merge_tags, $form_id, $fields, $element_id ) {
    // Add an All Fields (by input) tag
    $merge_tags[] = array(
        'label' => 'All Fields (by input)',
        'tag'   => '{all_fields_by_input}',
    );

    // Add per-checkbox-field tags
    foreach ( $fields as $field ) {
        if ( $field->type === 'checkbox' ) {
            $merge_tags[] = array(
                'label' => sprintf( '%s (labels by input)', GFCommon::get_label( $field ) ),
                'tag'   => sprintf( '{checkbox_labels:%d}', $field->id ),
            );
        }
    }
    return $merge_tags;
}, 10, 4 );

/**
 * Replace custom merge tags in messages.
 * Scope: only where you use these tags (typically notifications).
 */
add_filter( 'gform_replace_merge_tags', function ( $text, $form, $entry, $url_encode, $esc_html, $nl2br, $format ) {

    // {checkbox_labels:FIELD_ID}
    if ( preg_match_all( '/{checkbox_labels:(\d+)}/', $text, $matches ) ) {
        foreach ( $matches[1] as $i => $field_id_str ) {
            $field_id = (int) $field_id_str;
            $labels   = gf_fix_get_checkbox_labels_by_input( $form, $entry, $field_id );
            $text     = str_replace( $matches[0][ $i ], $labels, $text );
        }
    }

    // {all_fields_by_input}
    if ( strpos( $text, '{all_fields_by_input}' ) !== false ) {
        $rendered = gf_fix_render_all_fields_by_input( $form, $entry, $format );
        $text     = str_replace( '{all_fields_by_input}', $rendered, $text );
    }

    return $text;
}, 10, 7 );

/** Helper: get checkbox labels using input index alignment (handles duplicate values). */
function gf_fix_get_checkbox_labels_by_input( $form, $entry, $field_id ) {
    $field = GFFormsModel::get_field( $form, $field_id );
    if ( ! $field || $field->type !== 'checkbox' || ! is_array( $field->inputs ) ) {
        // Fallback to standard value if not a checkbox
        return GFCommon::get_lead_field_display( $field, rgar( $entry, (string) $field_id ), $entry, $form, 'html' );
    }

    $labels = array();

    foreach ( $field->inputs as $idx => $input ) {
        $input_id = (string) rgar( $input, 'id' ); // e.g., "3.2"
        if ( $input_id === '' ) {
            continue;
        }
        $selected_value = rgar( $entry, $input_id );
        if ( $selected_value === '' || $selected_value === null ) {
            continue; // not checked
        }
        // Match label by the SAME index as the input
        $choice = rgar( $field->choices, $idx );
        if ( is_array( $choice ) && isset( $choice['text'] ) ) {
            $labels[] = $choice['text'];
        } else {
            $labels[] = (string) $selected_value; // last resort
        }
    }

    return implode( ', ', $labels );
}

/** Helper: render an all-fields block but with fixed checkbox outputs. */
function gf_fix_render_all_fields_by_input( $form, $entry, $format = 'html' ) {
    // Simple, clean output that mirrors GF’s default email style
    $is_html = ( $format === 'html' );

    $rows = array();
    foreach ( $form['fields'] as $field ) {
        if ( rgar( $field, 'displayOnly' ) ) {
            continue;
        }

        $label = GFCommon::get_label( $field );
        $value = '';

        if ( $field->type === 'checkbox' ) {
            $value = gf_fix_get_checkbox_labels_by_input( $form, $entry, (int) $field->id );
        } else {
            $value = GFCommon::get_lead_field_display( $field, GFFormsModel::get_lead_field_value( $entry, $field ), $entry, $form, $format );
        }

        if ( $value === '' ) {
            continue;
        }

        $rows[] = $is_html
            ? sprintf( '<tr><td style="vertical-align:top;"><strong>%s</strong></td><td>%s</td></tr>', esc_html( $label ), wp_kses_post( $value ) )
            : sprintf( "%s: %s", $label, $value );
    }

    if ( $is_html ) {
        return '<table cellpadding="5" cellspacing="0" border="0" width="100%">' . implode( '', $rows ) . '</table>';
    }
    return implode( "\n", $rows );
}

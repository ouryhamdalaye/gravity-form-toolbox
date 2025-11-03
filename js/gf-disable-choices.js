/* ==== CONFIG ==== 
 * forms: {
 *   <formId>: {
 *     <fieldId>: [ 'Valeur à bloquer', 'Autre valeur', ... ]
 *   },
 *   ...
 * }
 */
/* ==== CONFIG ==== */
var cfg = {
  forms: {
    7: {
      21: {
        type: 'select',
        blocked: [
          'intensive',
        ],
        // true = masquer complètement ; false = désactiver (recommandé UX)
        hide: false
      },
    },
	10: {
      28: {
        'type': 'select',
        'blocked': [
          'L2',
		  'L3',
        ],
         hide: false
	  },
	},
  }
};

(function($){

  /* ===== utilitaires communs ===== */
  function fieldWrapper(formId, fieldId){ 
    return $('#input_' + formId + '_' + fieldId); 
  }
  function norm(v){ return (v || '').trim(); }

  /* ===== Checkbox ===== */
  function markCheckboxDisabled($inp){
    var $label = $inp.closest('.gchoice').find('label');
    if (!$label.length && $inp.attr('id')) {
      $label = $('label[for="'+$inp.attr('id')+'"]');
    }
    $inp.prop('disabled', true);
    if ($label.length){
      $label.addClass('gf-choice-disabled').attr('title', 'Option indisponible');
    }
  }

  function guardCheckboxField(formId, fieldId, blocked){
    var $wrap = fieldWrapper(formId, fieldId);
    if (!$wrap.length) return;

    $wrap.find('input.gfield-choice-input[type=checkbox]').each(function(){
      var v = norm(this.value);
      if (blocked.some(function(x){ return norm(x) === v; })) {
        // si déjà coché on le décoche
        if (this.checked) this.checked = false;
        markCheckboxDisabled($(this));
      }
    });
  }

 /* ===== SELECT (fix inclus) ===== */
  function guardSelectField(formId, fieldId, blocked, hide){
    var $el = $('#input_'+formId+'_'+fieldId); // ici c’est le <select id="input_7_27">
    if (!$el.length) return;

    // Supporte 2 cas : $el = <select> OU $el = wrapper contenant un <select>
    var $select = $el.is('select') ? $el : $el.find('select');
    if (!$select.length) return;

    var isMultiple = $select.prop('multiple');
    var isChosen   = !!$select.data('chosen') || $select.hasClass('chosen-select') || $select.next('.chosen-container').length;
    var isSelect2  = $select.hasClass('select2-hidden-accessible') || !!$select.data('select2');

    // 1) Marquer les options (désactiver ou masquer)
    $select.find('option').each(function(){
      var v = norm(this.value);
      if (!v) return; // ignorer placeholder vide
      if (!blocked.some(function(x){ return norm(x)===v; })) return;

      if (hide) {
        $(this).prop('disabled', true).addClass('gf-option-hidden').hide();
      } else {
        $(this).prop('disabled', true).addClass('gf-option-disabled').show();
      }
    });

    // 2) Nettoyer la sélection si une valeur interdite est sélectionnée
    function cleanSelection(){
      var curr = $select.val();
      if (!curr) return;

      if (isMultiple && Array.isArray(curr)) {
        var filtered = curr.filter(function(v){ return !blocked.some(function(x){ return norm(x)===norm(v); }); });
        if (filtered.length !== curr.length) $select.val(filtered);
      } else {
        if (blocked.some(function(x){ return norm(x)===norm(curr); })) {
          var $firstAllowed = $select.find('option').filter(function(){
            var vv = norm(this.value);
            var hidden = $(this).hasClass('gf-option-hidden') || $(this).css('display')==='none';
            return vv && !hidden && !this.disabled && !blocked.some(function(x){ return norm(x)===vv; });
          }).first();
          $select.val($firstAllowed.length ? $firstAllowed.val() : '');
        }
      }
    }

    cleanSelection();

    // 3) Empêcher une future sélection interdite
    $select.off('change.gfGuard').on('change.gfGuard', function(){
      cleanSelection();
      if (isChosen)  $select.trigger('chosen:updated');
      if (isSelect2) $select.trigger('change.select2');
    });

    // 4) Refresh visuel immédiat si UI enrichie
    if (isChosen)  $select.trigger('chosen:updated');
    if (isSelect2) $select.trigger('change.select2');
  }
	
 /* ===== 🔹 Radio ===== */
  function markRadioDisabled($inp){
    var $label = $inp.closest('.gchoice').find('label');
    if (!$label.length && $inp.attr('id')) $label = $('label[for="'+$inp.attr('id')+'"]');
    $inp.prop('disabled', true);
    if ($label.length) $label.addClass('gf-choice-disabled').attr('title','Option indisponible');
  }

  function guardRadioField(formId, fieldId, blocked, hide){
    var $wrap = fieldWrapper(formId, fieldId); // ex: #input_7_29
    if (!$wrap.length) return;

    var $radios = $wrap.find('input.gfield-choice-input[type=radio][name="input_'+fieldId+'"]');
    if (!$radios.length) return;

    // 1) Traiter chaque radio
    $radios.each(function(){
      var v = norm(this.value);
      if (!blocked.some(x => norm(x)===v)) return;

      if (hide) {
        // Masquer toute la .gchoice (input + label)
        $(this).closest('.gchoice').addClass('gf-choice-hidden').hide();
        $(this).prop('disabled', true);
      } else {
        // Désactiver et griser
        markRadioDisabled($(this));
      }
    });

    // 2) Si une radio bloquée est active, on la déselectionne et on choisit une autorisée
    var $checked = $radios.filter(':checked');
    if ($checked.length && blocked.some(x => norm(x)===norm($checked.val()))){
      // Chercher la première option autorisée visible & non disabled
      var $firstAllowed = $radios.filter(function(){
        var v = norm(this.value);
        var hidden = $(this).closest('.gchoice').hasClass('gf-choice-hidden') || $(this).closest('.gchoice').css('display')==='none';
        return !hidden && !this.disabled && !blocked.some(x => norm(x)===v);
      }).first();

      // Décocher l’ancienne
      $checked.prop('checked', false);

      // Sélectionner la nouvelle si dispo
      if ($firstAllowed.length){
        $firstAllowed.prop('checked', true).trigger('change');
      }
    }

    // 3) Empêcher un futur choix bloqué (si le DOM réactive l’input)
    $radios.off('change.gfGuard').on('change.gfGuard', function(){
      var v = norm(this.value);
      if (blocked.some(x => norm(x)===v)) {
        // revert immédiat
        $(this).prop('checked', false);
        // sélectionner une autorisée
        var $firstAllowed = $radios.filter(function(){
          var vv = norm(this.value);
          var hidden = $(this).closest('.gchoice').hasClass('gf-choice-hidden') || $(this).closest('.gchoice').css('display')==='none';
          return !hidden && !this.disabled && !blocked.some(x => norm(x)===vv);
        }).first();
        if ($firstAllowed.length){
          $firstAllowed.prop('checked', true).trigger('change');
        }
      }
    });
  }
	
  /* ===== Dispatcher ===== */
  function applyGuardsForForm(formId){
    var map = cfg.forms[formId];
    if (!map) return;

    Object.keys(map).forEach(function(fieldId){
      var conf = map[fieldId] || {};
      var type = (conf.type || '').toLowerCase();
      var blocked = conf.blocked || [];
      var hide = !!conf.hide;

      if (!blocked.length) return;

      if (type === 'checkbox') {
        guardCheckboxField(formId, fieldId, blocked);
      } else if (type === 'select') {
        guardSelectField(formId, fieldId, blocked, hide);
      } else if (type==='radio') {
		  guardRadioField(formId, fieldId, blocked, hide);
	  }

    });
  }

  function applyAll(){
    Object.keys(cfg.forms).forEach(function(fid){
      applyGuardsForForm(parseInt(fid,10));
    });
  }

  /* ===== Events GF (new + legacy) ===== */
  // GF ≥ 2.9
  document.addEventListener('gform/post_render', function(event){
    var formId = parseInt(event.detail && event.detail.formId, 10);
    if (!isNaN(formId) && cfg.forms[formId]) {
      applyGuardsForForm(formId);
    }
  });

  // Legacy
  $(document).on('gform_post_render', function(e, formId){
    formId = parseInt(formId, 10);
    if (!isNaN(formId) && cfg.forms[formId]) {
      applyGuardsForForm(formId);
    }
  });

  // Multi-étapes + logique conditionnelle
  $(document).on('gform_page_loaded gform_post_conditional_logic_field_action', function(){
    applyAll();
  });

  // Premier passage
  $(function(){ applyAll(); });

  // Styles (UX)
  $('<style>\
   .gf-choice-disabled{opacity:.6;pointer-events:none;user-select:none;}\
    .gf-choice-hidden{display:none;}\
    .gf-option-disabled{opacity:.6;}\
    .gf-option-hidden{display:none;}\
  </style>').appendTo('head');

})(jQuery);

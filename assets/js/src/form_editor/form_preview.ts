import jQuery from 'jquery';

jQuery(($) => {
  $(() => {
    const previewForm = $('div.mailpoet_form[data-is-preview="1"]');
    if (!previewForm.length) {
      return;
    }

    previewForm.submit((e) => { e.preventDefault(); return false; });

    const toggleClass = (form, from, to) => {
      form.removeClass(from);
      setTimeout(() => form.addClass(to));
    };

    const updateForm = (event) => {
      if (!event.data) {
        return;
      }
      // Allow message processing only when send from editor's origin
      const editorUrl = new URL(previewForm.data('editor-url'));
      if (editorUrl.origin !== event.origin) {
        return;
      }

      let width = null;
      const formType = event.data.formType;
      // Get width settings based on type
      if (formType === 'popup') {
        width = event.data.formSettings?.popupStyles.width;
      } else if (formType === 'fixed_bar') {
        width = event.data.formSettings?.fixedBarStyles.width;
      } else if (formType === 'slide_in') {
        width = event.data.formSettings?.slideInStyles.width;
      } else if (formType === 'below_post') {
        width = event.data.formSettings?.belowPostStyles.width;
      } else if (formType === 'others') {
        width = event.data.formSettings?.otherStyles.width;
      }

      if (!width) {
        return;
      }

      // Apply width settings
      const unit = width.unit === 'pixel' ? 'px' : '%';
      if (formType === 'fixed_bar') {
        const formElement = previewForm.find('form.mailpoet_form');
        formElement.css('width', `${width.value}${unit}`);
      } else {
        previewForm.css('width', `${width.value}${unit}`);
        if (unit === 'px') { // Update others container width to render full pixel size
          $('#mailpoet_widget_preview #sidebar').css('width', `${width.value}${unit}`);
        } else { // Reset container size to default render percent size
          $('#mailpoet_widget_preview #sidebar').css('width', null);
        }
      }

      // Ajdust others (widget) container
      if (formType === 'others') {
        if (unit === 'px') { // Update others container width so that we can render full pixel size
          $('#mailpoet_widget_preview #sidebar').css('width', `${width.value}${unit}`);
        } else { // Reset container size to default render percent size
          $('#mailpoet_widget_preview #sidebar').css('width', null);
        }
      }

      if (formType === 'slide_in') {
        if (previewForm.hasClass('mailpoet_form_position_left') && event.data.formSettings?.slideInFormPosition === 'right') {
          toggleClass(previewForm, 'mailpoet_form_position_left', 'mailpoet_form_position_right');
        } else if (previewForm.hasClass('mailpoet_form_position_right') && event.data.formSettings?.slideInFormPosition === 'left') {
          toggleClass(previewForm, 'mailpoet_form_position_right', 'mailpoet_form_position_left');
        }
      }

      if (formType === 'fixed_bar') {
        if (previewForm.hasClass('mailpoet_form_position_bottom') && event.data.formSettings?.fixedBarFormPosition === 'top') {
          toggleClass(previewForm, 'mailpoet_form_position_bottom', 'mailpoet_form_position_top');
        } else if (previewForm.hasClass('mailpoet_form_position_top') && event.data.formSettings?.fixedBarFormPosition === 'bottom') {
          toggleClass(previewForm, 'mailpoet_form_position_top', 'mailpoet_form_position_bottom');
        }
      }

      // Detect tight container
      previewForm.removeClass('mailpoet_form_tight_container');
      if (previewForm.width() < 400) {
        previewForm.addClass('mailpoet_form_tight_container');
      }
    };
    window.addEventListener('message', updateForm, false);

    // Display only form on widget preview page
    // This should keep element visible and still placed within the content but hide everything else
    const widgetPreview = $('#mailpoet_widget_preview');
    if (widgetPreview.length) {
      $('#mailpoet_widget_preview').siblings().hide();
      $('#mailpoet_widget_preview').parents().siblings().hide();
    }
  });
});

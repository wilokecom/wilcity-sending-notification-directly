/**
 * Created by pirates on 8/10/19.
 */
(function ($) {
  'use strict'
  var xhr = null

  $(document).ready(function () {
    $('#wilcity-sending-notification-form').on('submit', function (event) {
      event.preventDefault()
      const $self = $(this), $msg = $self.find('.wilcity-send-notification-msg')
      $self.addClass('loading')
      if (xhr !== null && xhr.status !== 200) {
        xhr.abort()
      }

      $msg.html('')
      $msg.removeClass('ui red green')
      $self.removeClass('error success')

      xhr = jQuery.ajax({
        type: 'POST',
        url: ajaxurl,
        data: {
          action: 'wilcity_send_notification',
          data: $self.serializeArray(),
          security: $('#wilcity-snd-nonce-field').val()
        },
        success: function (response) {
          if (response.success) {
            $msg.html(response.data.msg)
            $self.addClass('success')
            $msg.addClass('ui green')
          } else {
            $msg.html(response.data.msg)
            $self.addClass('error')
            $msg.addClass('ui red')
          }

          $self.removeClass('loading')
        }
      })
    })

    // $.fn.api.settings.api = {
    //   'search' : ajaxurl + '?action=wilcity_snd_search_user&send_from={value}'
    // };
    //
    // $('.search input').api({
    //   debug: true,
    //   action: 'search',
    //   searchFullText: false,
    //   stateContext: '.ui.input'
    // })

    $('.search')
      .search({
        apiSettings: {
          url: ajaxurl + '?action=wilcity_snd_search_user&search={query}'
        },
        fields: {
          results: 'results',
          title: 'user_login'
        },
        onSelect: function(result, response) {
          console.log(result);
          console.log(response);
        },
        minCharacters: 2
      })

    $('.wilcity-snd-cancel').each(function(){
      $(this).on('click', function(){
        var ask = confirm('Do you want to cancel this notification?');
        const $this = $(this);

        if (!ask) {
          return false;
        }

        $this.addClass('loading');

        jQuery.ajax({
          type: 'POST',
          url: ajaxurl,
          data: {
            action: 'wilcity_cancel_notification',
            id: $this.data('key'),
            security: $('#wilcity-snd-nonce-field').val()
          },
          success: function (response) {
            if (response.success) {
              $this.remove();
            } else {
              $this.removeClass('loading');
              alert(response.data.msg);
            }
          }
        })
      })
    });
  })

})(jQuery)

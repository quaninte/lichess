$(function() { 

    var $wrap = $('div.hooks_wrap');
    if (!$wrap.length) {
        return;
    }
    var $chat = $("div.lichess_chat");
    var $hooks = $wrap.find('div.hooks');
    var pollUrl = $hooks.data('poll-url');
    var actionUrls = {
        'cancel': $hooks.data('cancel-url'),
        'join': $hooks.data('join-url')
    };
    var slowDelay = 3000, fastDelay = delay = 100;
    var state = 0;
    var messageId = 0;
    var auth = $hooks.data('auth');
    var frozen = false;

    function chat() {
        var $form = $chat.find('form');
        $chat.find('ol.lichess_messages')[0].scrollTop = 9999999;
        var $input = $chat.find('input.lichess_say').one("focus", function() {
            $input.val('').removeClass('lichess_hint');
        });

        // send a message
        $form.submit(function() {
            text = $.trim($input.val());
            if (!text) return false;
            if (text.length > 140) {
                alert('Max length: 140 chars. ' + text.length + ' chars used.');
                return false;
            }
            $input.val('');
            $.ajax($form.attr("action"), {
                data: {
                    message: text
                },
                type: 'POST',
                timeout: 8000
            });
            return false;
        });

        $chat.find('a.send').click(function() {
            $input.trigger('click');
            $form.submit();
        });

        // toggle the chat
        $chat.find('input.toggle_chat').change(function() {
            $chat.toggleClass('hidden', ! $(this).attr('checked'));
        }).trigger('change');
    };
    chat();

    function reload() {
        setTimeout(function() {
            if (frozen) return;
            $.ajax(pollUrl, {
                success: function(data) {
                    if (frozen) return;
                    if (data.redirect) {
                        freeze();
                        location.href = 'http://'+location.hostname+'/'+data.redirect;
                    } else {
                        state = data.state
                        renderHooks(data.pool);
                        renderChat(data.chat);
                    }
                },
                complete: function() {
                    reload();
                },
                dataType: 'json',
                type: "GET",
                cache: false,
                data: {
                    'state': state,
                    'messageId': messageId,
                    'auth': auth
                },
                timeout: 15000
            });
        },
        200);
    };
    reload();

    function renderChat(data) {
        messageId = data.id;
        var html = "";
        for (i in data.messages) {
            msg = data.messages[i];
            html += '<li' + (msg["u"] == "[bot]" ? ' class="bot"' : '') + '><span>'
            if (msg["r"]) {
                html += '<a class="user_link" href="/@/'+msg["u"]+'">'+msg["u"] + '</a>';
            } else if (msg["u"] != "[bot]") {
                html += msg["u"];
            }
            html += '</span>' + msg['m'] + '</li>';
        }
        if (html != "") {
            $chat.find('ol.lichess_messages').append(html)[0].scrollTop = 9999999;
        }
    }
    function renderHooks(data) {
        if (data.hooks) {
            var hook, html = '<table>';
            for (id in data.hooks) {
                hook = data.hooks[id];
                html += '<tr'+(hook.action == 'join' ? ' class="joinable"' : '')+'>';
                html += '<td class="color"><span class="'+hook.color+'"></span></td>';
                if (hook.elo) {
                    html += '<td><a class="user_link" href="/@/'+hook.username+'">'+hook.username+'<br />('+hook.elo+')</a></td>';
                } else {
                    html += '<td>'+hook.username+'</td>';
                }
                html += '</td>';
                if (hook.variant == 'Chess960') {
                    html += '<td><a href="http://en.wikipedia.org/wiki/Chess960"><strong>960</strong></a></td>';
                } else {
                    html += '<td></td>';
                }
                html += '<td>'+hook.mode+'</td>';
                html += '<td>'+hook.clock+'</td>';
                html += '<td class="action">';
                html += '<a href="'+actionUrls[hook.action].replace(/\/0{8,12}\//, '/'+hook.id+'/')+'" class="'+hook.action+'"></a>';
                html += '</td></tr>';
            }
        } else {
            var html = '<table class="empty_table"><tr><td colspan="5">'+data.message+'</td></tr></table>';
        }
        $hooks.html(html).find('a.join').click(freeze);
        $wrap.removeClass('hidden');
        $('body').trigger('lichess.content_loaded');
    }

    function freeze() {
        $.lichessOpeningPreventClicks();
        frozen = true;
    }

    $hooks.on('click', 'table.empty_table tr', function() {
        $('#start_buttons a.config_hook').click();
    });

    if (data = $hooks.data('hooks')) {
        state = data.state;
        renderHooks(data);
    }
    if (hookId = $hooks.data('my-hook')) {
        $.data(document.body, 'lichess_ping').setData('hook_id', hookId);
    }

    $(window).on('blur', function() {
        delay = slowDelay;
    }).on('focus', function() {
        delay = fastDelay;
    });
});

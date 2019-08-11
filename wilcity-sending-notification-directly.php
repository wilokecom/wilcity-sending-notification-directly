<?php
/**
 * Plugin Name: Wilcity Sending Notification Directly
 * Plugin URI: https://wilcityservice.com
 * Author: Wiloke
 * Author URI: https://wiloke.com
 * Version: 1.0
 */

use \WilokeListingTools\Models\NotificationsModel;
use \WilokeListingTools\Framework\Helpers\GetSettings;
use \WilokeListingTools\Framework\Helpers\Time;
use \WilokeListingTools\Frontend\User;
use \WilokeListingTools\Framework\Helpers\SetSettings;

define('WILCITY_SND_VERSION', '1.0');

$wilcitySNDSlug    = 'wilcity-sending-notification';
$aWilcitySNDFields = [
    [
        'type'        => 'search',
        'id'          => 'wilcity-notification-send-from',
        'name'        => 'wilcity_notification_send_from',
        'label'       => 'Send From',
        'desc'        => 'Leave empty to means this notification will send your account',
        'required'    => false,
        'sanitize_cb' => 'sanitize_text_field',
        'value'       => ''
    ],
    [
        'type'        => 'text',
        'id'          => 'wilcity-notification-title',
        'name'        => 'wilcity_notification_title',
        'label'       => 'Title',
        'sanitize_cb' => 'sanitize_text_field',
        'required'    => true,
        'value'       => ''
    ],
    [
        'type'        => 'textarea',
        'id'          => 'wilcity-notification-message',
        'name'        => 'wilcity_notification_message',
        'label'       => 'Message',
        'sanitize_cb' => 'sanitize_text_field',
        'required'    => true,
        'value'       => ''
    ],
    [
        'type'        => 'select',
        'id'          => 'wilcity-notification-send-to-where',
        'name'        => 'wilcity_notification_send_where',
        'label'       => 'Send To Web / App',
        'sanitize_cb' => 'sanitize_text_field',
        'required'    => true,
        'value'       => '',
        'options'     => [
            'all' => 'both',
            'web' => 'Web only',
            'app' => 'App only'
        ]
    ],
    [
        'type'        => 'select',
        'id'          => 'wilcity-notification-send-to',
        'name'        => 'wilcity_notification_send_to',
        'label'       => 'Send To Customer Mode',
        'sanitize_cb' => 'sanitize_text_field',
        'required'    => true,
        'value'       => '',
        'options'     => [
            'all'         => 'All users',
            'followingme' => 'Users who are following me',
            'custom'      => 'Custom'
        ]
    ],
    [
        'type'        => 'textarea',
        'id'          => 'wilcity-notification-send-to-custom',
        'name'        => 'wilcity_notification_send_to_custom',
        'label'       => 'Customer\'s username',
        'sanitize_cb' => 'sanitize_text_field',
        'desc'        => 'If you are using Send To Custom mode, please enter customer username in this field. Each customer should be separated by a comma',
        'required'    => false,
        'value'       => '',
        'dependency'  => [
            'name'  => 'wilcity_notification_send_to',
            'value' => 'custom'
        ]
    ],
];

function wilcitySNDVerifyNonce()
{
    if (check_ajax_referer('security', 'wilcity-snd-nonce-field', false)) {
        wp_send_json_error([
            'msg' => 'Invalid Security Code'
        ]);
    }
}

add_action('wp_ajax_wilcity_cancel_notification', function () {
    wilcitySNDVerifyNonce();

    $id            = $_POST['id'];
    $aScheduleInfo = GetSettings::getOptions($id);

    if (!empty($aScheduleInfo)) {
        $aScheduleInfo = array_map(function ($item) {
            return is_numeric($item) ? abs($item) : $item;
        }, $aScheduleInfo);
        wp_clear_scheduled_hook('wilcity_send_notification_directly', $aScheduleInfo);
    }

    $aListOfSchedules = GetSettings::getOptions('snd_list_of_schedule');
    $findIndex        = array_search($id, $aListOfSchedules);
    unset($aListOfSchedules[$findIndex]);
    SetSettings::setOptions('snd_list_of_schedule', $aListOfSchedules);

    wp_send_json_success();
});

add_filter('wilcity/wiloke-listing-tools/get-notification/send_notification_directly', function ($response, $oInfo) {
    $optionKey        = $oInfo->objectID;
    $aNotificationIDs = GetSettings::getOptions('store_snd_id');
    $aOption          = GetSettings::getOptions($aNotificationIDs[$optionKey]);

    return [
        'title'       => $aOption['wilcity_notification_title'],
        'featuredImg' => User::getAvatar($oInfo->senderID),
        'link'        => '#',
        'content'     => $aOption['wilcity_notification_message'],
        'time'        => Time::timeFromNow(strtotime($oInfo->date)),
        'type'        => 'send_notification_directly',
        'ID'          => absint($oInfo->ID)
    ];
}, 10, 2);

function wilcitySNDParseNotification($aParseData, $lastSendTo = 0)
{
    global $wpdb;

    $isSetSchedule = false;
    $aSendTo       = [];

    switch ($aParseData['wilcity_notification_send_to']) {
        case 'custom':
            $aParseRawSendToIDs = explode(',', $aParseData['wilcity_notification_send_to_custom']);
            foreach ($aParseRawSendToIDs as $userLogin) {
                $oUser = get_user_by('login', trim($userLogin));
                if (!empty($oUser) && !is_wp_error($oUser)) {
                    $aSendTo[] = abs($oUser->ID);
                }
            }
            break;
        case 'followingme':
            $followTbl = $wpdb->prefix.\WilokeListingTools\AlterTable\AlterTableFollower::$tblName;

            $query = $wpdb->prepare(
                "SELECT followerID FROM $followTbl WHERE authorID=%d",
                $aParseData['sendFrom']
            );

            if (!empty($lastSendTo)) {
                $query = $wpdb->prepare(
                    $query." AND ID > %d",
                    $lastSendTo
                );
            }

            $query .= " ORDER BY date ASC LIMIT 50";

            $aRawFollowingIDs = $wpdb->get_results($query);
            if (!empty($aRawFollowingIDs) && !is_wp_error($aRawFollowingIDs)) {
                foreach ($aRawFollowingIDs as $oUser) {
                    $aSendTo[] = abs($oUser->followerID);
                }
            }
            $isSetSchedule = true;
            break;
        default:
            $query = $wpdb->prepare(
                "SELECT ID FROM $wpdb->users WHERE ID != %d",
                $aParseData['sendFrom']
            );

            if (!empty($lastSendTo)) {
                $query = $wpdb->prepare(
                    $query." AND ID > %d",
                    $lastSendTo
                );
            }
            $query     .= " ORDER BY ID ASC LIMIT 50";
            $aRawUsers = $wpdb->get_results($query);

            if (!empty($aRawUsers) && !is_wp_error($aRawUsers)) {
                foreach ($aRawUsers as $oUser) {
                    $aSendTo[] = abs($oUser->ID);
                }
            }
            $isSetSchedule = true;
    }

    return [
        'isSetSchedule' => $isSetSchedule,
        'aSendTo'       => $aSendTo
    ];
}

function wilcitySNDSend($aNotificationInfo, $aNotificationSettings, $optionKey, $objectID)
{
    if ($aNotificationSettings['wilcity_notification_send_where'] == 'app' || $aNotificationSettings['wilcity_notification_send_where'] == 'all') {
        do_action('wilcity/wilcity-mobile-app/send-push-notification-directly', $aNotificationInfo['aSendTo'],
            $aNotificationSettings['wilcity_notification_message']);
    }

    if ($aNotificationSettings['wilcity_notification_send_where'] == 'web' || $aNotificationSettings['wilcity_notification_send_where'] == 'all') {
        foreach ($aNotificationInfo['aSendTo'] as $userID) {
            NotificationsModel::add($userID, 'send_notification_directly', $objectID, $aNotificationSettings['sendFrom']);
        }
    }

    if ($aNotificationInfo['isSetSchedule']) {
        $aScheduleInfo = [
            array_pop($aNotificationInfo['aSendTo']),
            $optionKey,
            $objectID
        ];
        wp_schedule_single_event(\time() + 600, 'wilcity_send_notification_directly', $aScheduleInfo);

        SetSettings::setOptions($optionKey.'_last_schedule', $aScheduleInfo);

        $aListOfSchedules = GetSettings::getOptions('snd_list_of_schedule');
        if (empty($aListOfSchedules)) {
            $aListOfSchedules = [];
        }

        if (!array_search($optionKey.'_last_schedule', $aListOfSchedules)) {
            $aListOfSchedules[] = $optionKey.'_last_schedule';
            SetSettings::setOptions('snd_list_of_schedule', $aListOfSchedules);
        }
    }
}

add_action('wilcity_send_notification_directly', function (
    $lastCustomerSendTo,
    $optionKey,
    $objectID
) {

    $aNotificationSettings = GetSettings::getOptions($optionKey);
    if (empty($aNotificationSettings)) {
        return false;
    }

    $aNotificationInfo = wilcitySNDParseNotification($aNotificationSettings, $lastCustomerSendTo);
    if (!empty($aNotificationInfo['aSendTo'])) {
        wilcitySNDSend($aNotificationInfo, $aNotificationSettings, $optionKey, $objectID);
    } else {
        $aScheduleInfo = GetSettings::getOptions($optionKey.'_last_schedule');
        wp_clear_scheduled_hook('wilcity_send_notification_directly', $aScheduleInfo);

        $aListOfSchedules = GetSettings::getOptions('snd_list_of_schedule');
        $findIndex        = array_search($optionKey.'_last_schedule', $aListOfSchedules);
        unset($aListOfSchedules[$findIndex]);
        SetSettings::setOptions('snd_list_of_schedule', $aListOfSchedules);
    }

}, 10, 4);

add_action('wp_ajax_wilcity_send_notification', function () {
    wilcitySNDVerifyNonce();

    global $aWilcitySNDFields;

    $aNotificationSettings = [];
    foreach ($_POST['data'] as $aRawValue) {
        $aNotificationSettings[$aRawValue['name']] = $aRawValue['value'];
    }

    $aRequires = array_filter($aWilcitySNDFields, function ($aField) {
        return (isset($aField['required']) && $aField['required']) || isset($aField['dependency']);
    });

    $aSanitizeFunctions = array_filter($aWilcitySNDFields, function ($aField) {
        return isset($aField['sanitize_cb']);
    });

    if (!empty($aRequires)) {
        foreach ($aRequires as $aField) {
            if (!isset($aField['dependency'])) {
                if (!isset($aNotificationSettings[$aField['name']]) || empty($aNotificationSettings[$aField['name']])) {
                    wp_send_json_error([
                        'msg' => sprintf('The %s is required', $aField['label'])
                    ]);
                }
            } else {
                if (isset($aField['dependency'])) {
                    $parentName = $aRequires['dependency']['name'];
                    if (isset($aNotificationSettings[$parentName]) && $aNotificationSettings[$parentName] == $aRequires['dependency']['value']) {
                        if (!isset($aNotificationSettings[$aField['name']]) || empty($aNotificationSettings[$aField['name']])) {
                            wp_send_json_error([
                                'msg' => sprintf('The %s is required', $aRequires['label'])
                            ]);
                        }
                    }
                }
            }
        }
    }

    if (!empty($aSanitizeFunctions)) {
        foreach ($aSanitizeFunctions as $aField) {
            if (isset($aNotificationSettings[$aField['name']]) && !empty($aNotificationSettings[$aField['name']])) {
                $aNotificationSettings[$aField['name']] = call_user_func($aField['sanitize_cb'],
                    $aNotificationSettings[$aField['name']]);
            }
        }
    }

    if (empty($aNotificationSettings['wilcity_notification_send_from'])) {
        $sendFrom = get_current_user_id();
    } else {
        $oUser    = get_user_by('login', $aNotificationSettings['wilcity_notification_send_from']);
        $sendFrom = abs($oUser->ID);
    }

    $aNotificationInfo = wilcitySNDParseNotification($aNotificationSettings);
    if (empty($aNotificationInfo['aSendTo'])) {
        wp_send_json_error([
            'msg' => 'We found no customer'
        ]);
    }

    $aNotificationIDs = GetSettings::getOptions('store_snd_id');
    $aNotificationIDs = empty($aNotificationIDs) ? [] : $aNotificationIDs;

    $sndOptionKey       = uniqid('snd_');
    $aNotificationIDs[] = $sndOptionKey;
    unset($aNotificationSettings['wilcity-snd-nonce-field']);
    unset($aNotificationSettings['_wp_http_referer']);

    $aParseData['sendFrom'] = $sendFrom;
    SetSettings::setOptions($sndOptionKey, $aNotificationSettings);
    SetSettings::setOptions('store_snd_id', $aNotificationIDs);
    $objectID = array_pop(array_keys($aNotificationIDs));

    wilcitySNDSend($aNotificationInfo, $aNotificationSettings, $sndOptionKey, $objectID);

    wp_send_json_success([
        'msg' => 'Congratulations! The notification has been setup and it will be send sooner'
    ]);
});

add_action('wp_ajax_wilcity_snd_search_user', function () {
    if (!isset($_GET['search']) || empty($_GET['search'])) {
        wp_send_json_error();
    }

    global $wpdb;
    $aUsers = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT user_login FROM $wpdb->users WHERE user_login LIKE %s ORDER BY ID ASC LIMIT 50",
            '%'.trim($_GET['search']).'%'
        ),
        ARRAY_A
    );

    if (empty($aUsers)) {
        wp_send_json_error();
    }

    echo json_encode([
        'results' => $aUsers
    ]);
    die;
});

add_action('wiloke-listing-tools/run-extension', function () {
    add_action('admin_menu', function () {
        global $wilcitySNDSlug;
        add_menu_page(
            'Wilcity Sending Notification',
            'Wilcity Sending Notification',
            'administrator',
            $wilcitySNDSlug,
            function () {
                global $aWilcitySNDFields;
                ?>
                <div class="ui segment">
                    <h2>Sending Notification</h2>
                    <form id="wilcity-sending-notification-form" class="form ui" method="POST">
                        <div class="wilcity-send-notification-msg message"></div>
                        <?php wp_nonce_field('wilcity-snd-nonce-action', 'wilcity-snd-nonce-field'); ?>
                        <?php foreach ($aWilcitySNDFields as $fieldKey => $aField) : ?>
                            <div class="ui field <?php echo $aField['type']; ?>">
                                <?php if ($aField['type'] !== 'search') : ?>
                                    <label for="<?php echo $aField['id']; ?>"><?php echo $aField['label']; ?></label>
                                <?php endif; ?>
                                <?php
                                $val = isset($aField['value']) ? $aField['value'] : '';
                                switch ($aField['type']) :
                                    case 'text':
                                        ?>
                                        <input name="<?php echo $aField['name']; ?>" id="<?php echo $aField['id'];
                                        ?>" value="<?php echo $val; ?>">
                                        <?php
                                        break;
                                    case 'search':
                                        ?>
                                        <input type="text" class="prompt" name="<?php echo $aField['name']; ?>"
                                               id="<?php echo $aField['id']; ?>" value="<?php echo $val; ?>"
                                               placeholder="<?php echo $aField['label']; ?>">
                                        <div class="results"></div>
                                        <?php
                                        break;
                                    case 'textarea':
                                        ?>
                                        <textarea name="<?php echo $aField['name']; ?>" id="<?php echo $aField['id'];
                                        ?>"><?php echo $val; ?></textarea>
                                        <?php
                                        break;
                                    case 'select':
                                        ?>
                                        <select name="<?php echo $aField['name']; ?>" id="<?php echo $aField['id'];
                                        ?>">
                                            <?php foreach ($aField['options'] as $value => $name): ?>
                                                <option <?php selected($value, $aField['value']); ?>
                                                        value="<?php
                                                        echo
                                                        $value;
                                                        ?>"><?php echo
                                                    $name;
                                                    ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <?php
                                        break;
                                endswitch;
                                ?>
                            </div>
                            <?php if (isset($aField['desc'])) : ?>
                                <p class="message ui info"><?php echo $aField['desc']; ?></p>
                            <?php endif; ?>
                        <?php endforeach; ?>

                        <button class="ui button green">Send</button>
                    </form>
                </div>

                <?php $aListingOfScheduling = GetSettings::getOptions('snd_list_of_schedule'); ?>
                <?php if (!empty($aListingOfScheduling)) : ?>
                    <div class="ui segment">
                        <h2>Click to cancel a sending notification</h2>
                        <?php
                        foreach ($aListingOfScheduling as $notificationKey) {
                            $settingKey = str_replace('_last_schedule', '', $notificationKey);
                            $aOption    = GetSettings::getOptions($settingKey);
                            ?>
                            <button class="ui red button wilcity-snd-cancel" data-key="<?php echo $notificationKey;
                            ?>"><?php echo isset
                                ($aOption['wilcity_notification_title']) ?
                                    $aOption['wilcity_notification_title'] : $notificationKey;
                                ?></button>
                            <?php
                        }
                        ?>
                    </div>
                <?php endif; ?>
                <?php
            },
            'dashicons-awards',
            20
        );
    });

    add_action('admin_enqueue_scripts', function () {
        global $wilcitySNDSlug;
        if (!isset($_REQUEST['page']) || $_REQUEST['page'] !== $wilcitySNDSlug) {
            return false;
        }

        wp_enqueue_script('semantic-ui', WILOKE_LISTING_TOOL_URL.'admin/assets/semantic-ui/semantic.min.js', ['jquery'],
            WILCITY_SND_VERSION, true);
        wp_enqueue_style('semantic-ui', WILOKE_LISTING_TOOL_URL.'admin/assets/semantic-ui/form.min.css', [],
            WILCITY_SND_VERSION, false);

        wp_enqueue_script('wilcity-sending-notification-directly', plugin_dir_url(__FILE__).'script.js', ['jquery'],
            WILCITY_SND_VERSION, true);
    });
});

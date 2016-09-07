<?php

use DBStatic\DBStaticUser;

$classLocale = classLocale::$lang;

if(classSupernova::$config->server_updater_check_auto && classSupernova::$config->server_updater_check_last + classSupernova::$config->server_updater_check_period <= SN_TIME_NOW) {
  include(SN_ROOT_PHYSICAL . 'ajax_version_check' . DOT_PHP_EX);
}

if(classSupernova::$config->user_birthday_gift && SN_TIME_NOW - classSupernova::$config->user_birthday_celebrate > PERIOD_DAY) {
  require_once(SN_ROOT_PHYSICAL . "includes/includes/user_birthday_celebrate" . DOT_PHP_EX);
  sn_user_birthday_celebrate();
}

if(!classSupernova::$config->var_online_user_count || classSupernova::$config->var_online_user_time + 30 < SN_TIME_NOW) {
  classSupernova::$config->db_saveItem('var_online_user_count', DBStaticUser::db_user_count(true));
  classSupernova::$config->db_saveItem('var_online_user_time', SN_TIME_NOW);
  if(classSupernova::$config->server_log_online) {
    db_log_online_insert();
  }
}


global $user;
$result = classSupernova::$auth->login();

global $account_logged_in;
$account_logged_in = !empty(classSupernova::$auth->account) && $result[F_LOGIN_STATUS] == LOGIN_SUCCESS;

//pdump($result[F_LOGIN_STATUS], LOGIN_SUCCESS);
// die();

// pdump($result);die();

$user = !empty($result[F_USER]) ? $result[F_USER] : false;

unset($result[F_USER]);
$template_result += $result;
unset($result);
// В этой точке пользователь либо авторизирован - и есть его запись - либо пользователя гарантированно нет в базе

$template_result[F_ACCOUNT_IS_AUTHORIZED] = $sys_user_logged_in = !empty($user) && isset($user['id']) && $user['id'];
//pdump($template_result[F_ACCOUNT_IS_AUTHORIZED]);die();

if(!empty($user['id'])) {
  classSupernova::$user_options->user_change($user['id']);
}

// Если сообщение пустое - заполняем его по коду
$template_result[F_LOGIN_MESSAGE] =
  isset($template_result[F_LOGIN_MESSAGE]) && $template_result[F_LOGIN_MESSAGE]
    ? $template_result[F_LOGIN_MESSAGE]
    : ($template_result[F_LOGIN_STATUS] != LOGIN_UNDEFINED
    ? classLocale::$lang['sys_login_messages'][$template_result[F_LOGIN_STATUS]]
    : false
  );

if($template_result[F_LOGIN_STATUS] == LOGIN_ERROR_USERNAME_RESTRICTED_CHARACTERS) {
  $prohibited_characters = array_map(function ($value) {
    return "'" . htmlentities($value, ENT_QUOTES, 'UTF-8') . "'";
  }, str_split(LOGIN_REGISTER_CHARACTERS_PROHIBITED));
  $template_result[F_LOGIN_MESSAGE] .= implode(', ', $prohibited_characters);
}


if(defined('DEBUG_AUTH') && DEBUG_AUTH && !defined('IN_AJAX')) {
  pdump("Отключи отладку перед продакшном!");
}


// Это уже переключаемся на пользовательский язык с откатом до языка в параметрах запроса
classLocale::$lang->lng_switch(sys_get_param_str('lang'));
global $dpath;
$dpath = $user["dpath"] ? $user["dpath"] : DEFAULT_SKINPATH;

classSupernova::$config->db_loadItem('game_disable') == GAME_DISABLE_INSTALL
  ? define('INSTALL_MODE', GAME_DISABLE_INSTALL)
  : false;

if(
  classSupernova::$config->game_disable == GAME_DISABLE_STAT
  &&
  SN_TIME_NOW - strtotime(classSupernova::$config->db_loadItem('var_stat_update_end')) > 600
) {
  $next_run = date(FMT_DATE_TIME_SQL, sys_schedule_get_prev_run(classSupernova::$config->stats_schedule, classSupernova::$config->var_stat_update, true));
  classSupernova::$config->db_saveItem('game_disable', GAME_DISABLE_NONE);
  classSupernova::$config->db_saveItem('var_stat_update', SN_TIME_SQL);
  classSupernova::$config->db_saveItem('var_stat_update_next', $next_run);
  classSupernova::$config->db_saveItem('var_stat_update_end', SN_TIME_SQL);
  classSupernova::$debug->warning('Stat worked too long - watchdog unlocked', 'Stat WARNING');
}

if($template_result[F_GAME_DISABLE] = classSupernova::$config->game_disable) {
  $template_result[F_GAME_DISABLE_REASON] = sys_bbcodeParse(
    classSupernova::$config->game_disable == GAME_DISABLE_REASON
      ? classSupernova::$config->game_disable_reason
      : classLocale::$lang['sys_game_disable_reason'][classSupernova::$config->game_disable]
  );
  if(defined('IN_API')) {
    return;
  }

  if(
    ($user['authlevel'] < 1 || !(defined('IN_ADMIN') && IN_ADMIN))
    &&
    !(defined('INSTALL_MODE') && defined('LOGIN_LOGOUT'))
  ) {
    message($template_result[F_GAME_DISABLE_REASON], classSupernova::$config->game_name);
    ob_end_flush();
    die();
  }
}


// TODO ban
// TODO $skip_ban_check
if($template_result[F_BANNED_STATUS] && !$skip_ban_check) {
  if(defined('IN_API')) {
    return;
  }

  $bantime = date(FMT_DATE_TIME, $template_result[F_BANNED_STATUS]);
  // TODO: Add ban reason. Add vacation time. Add message window
  // sn_sys_logout(false, true);
  // core_auth::logout(false, true);
  message("{$classLocale['sys_banned_msg']} {$bantime}", classLocale::$lang['ban_title']);
  die("{$classLocale['sys_banned_msg']} {$bantime}");
}

// TODO !!! Просто $allow_anonymous используется в платежных модулях !!!
$allow_anonymous = $allow_anonymous || (isset($sn_page_data['allow_anonymous']) && $sn_page_data['allow_anonymous']);

// pdump($allow_anonymous, '$allow_anonymous');
// pdump($sys_user_logged_in, '$sys_user_logged_in');

if($sys_user_logged_in && INITIAL_PAGE == 'login') {
  sys_redirect(SN_ROOT_VIRTUAL . 'overview.php');
} elseif($account_logged_in && !$sys_user_logged_in) { // empty(core_auth::$user['id'])
//  pdump($sn_page_name);
//  pdump(INITIAL_PAGE);
//  die('{Тут должна быть ваша реклама. Точнее - ввод имени игрока}');
} elseif(!$allow_anonymous && !$sys_user_logged_in) {
  // sn_setcookie(SN_COOKIE, '', time() - PERIOD_WEEK, SN_ROOT_RELATIVE);
  sys_redirect(SN_ROOT_VIRTUAL . 'login.php');
}

$user_time_diff = playerTimeDiff::user_time_diff_get();
global $time_diff;
define('SN_CLIENT_TIME_DIFF', $time_diff = $user_time_diff[PLAYER_OPTION_TIME_DIFF] + $user_time_diff[PLAYER_OPTION_TIME_DIFF_UTC_OFFSET]);
define('SN_CLIENT_TIME_LOCAL', SN_TIME_NOW + SN_CLIENT_TIME_DIFF);
define('SN_CLIENT_TIME_DIFF_GMT', $user_time_diff[PLAYER_OPTION_TIME_DIFF]); // Разница в GMT-времени между клиентом и сервером. Реальная разница в ходе часов

!empty($user) && sys_get_param_id('only_hide_news') ? die(nws_mark_read($user)) : false;
!empty($user) && sys_get_param_id('survey_vote') ? die(survey_vote($user)) : false;

!empty(classSupernova::$sn_mvc['i18n']['']) ? lng_load_i18n(classSupernova::$sn_mvc['i18n']['']) : false;
$sn_page_name && !empty(classSupernova::$sn_mvc['i18n'][$sn_page_name]) ? lng_load_i18n(classSupernova::$sn_mvc['i18n'][$sn_page_name]) : false;

execute_hooks(classSupernova::$sn_mvc['model'][''], $template, 'model', '');

global $skip_fleet_update;
$skip_fleet_update = $skip_fleet_update || classSupernova::$options['fleet_update_skip'] || defined('IN_ADMIN');
if(
  !$skip_fleet_update
  && !(defined('IN_AJAX') && IN_AJAX === true)
  && SN_TIME_NOW - strtotime(classSupernova::$config->fleet_update_last) > classSupernova::$config->fleet_update_interval
) {
  require_once(SN_ROOT_PHYSICAL . "includes/includes/flt_flying_fleet_handler2" . DOT_PHP_EX);
  flt_flying_fleet_handler($skip_fleet_update);
}

if(!defined('IN_AJAX')) {
  pdump("Scheduled processes is disabled");
}
// scheduler_process();

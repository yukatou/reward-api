<?php

$identifier				=	Input::get('identifier');		//ユーザの紹介コードが送られてくる
$achieve_id				=	Input::get('achieve_id');
$accepted_time    =	Input::get('accepted_time');
$campaign_name    =	Input::get('campaign_name');
$advertisement_id =	Input::get('advertisement_id');
$advertisement_name =	Input::get('advertisement_name');
$point					=	Input::get('point');
$payment				=	Input::get('payment');
$dateval				=	date("c");

$user_id;

if (validate_remote_address() == false) {
  Log::info('invalid_IP:'.json_encode(Input::real_ip()));
  Response::forge('', 500)->send(true);
  exit;
}

$ret = validate_user($identifier,$user_id);
if($ret != true){
  Response::forge('', 400)->send(true);
  exit;
}

$count= getRecCount('t_app_driver',$identifier,$achieve_id,1);
//--------------------------------------
//アイテム付与済、アドウェイズ指定の0を返却
//--------------------------------------
if ($count > 0){
  //--------------------------------------
  //	 正常終了（ポイント付与済）
  //--------------------------------------			
  echo 0;
  exit();
}

try {

  DB::start_transaction();
  //--------------------------------------
  //t_app_driver insert
  //--------------------------------------
  $sql = <<<_SQL
    INSERT INTO t_app_driver (
      identifier
      ,achieve_id
      ,accepted_time
      ,campaign_name
      ,advertisement_id
      ,advertisement_name
      ,point
      ,payment
      ,created_at
    )
    VALUES	 (
      :identifier
      ,:achieve_id
      ,:accepted_time
      ,:campaign_name
      ,:advertisement_id
      ,:advertisement_name
      ,:point
      ,:payment
      ,:created_at
    )
    _SQL
    ;

  $q = DB::query($sql)
    ->bind('identifier', $identifier)
    ->bind('achieve_id', $achieve_id)
    ->bind('accepted_time', $accepted_time)
    ->bind('campaign_name', $campaign_name)
    ->bind('advertisement_id', $advertisement_id)
    ->bind('advertisement_name', $advertisement_name)
    ->bind('point', $point)
    ->bind('payment', $payment)
    ->bind('created_at', $dateval)
    ->execute()
    ;

  //--------------------------------------
  // アイテム付与
  //--------------------------------------
  $ret = get_item($user_id,$point);

  if($ret != true){
    DB::rollback_transaction();
    Response::forge('', 400)->send(true);
    exit;
  }

  //--------------------------------------
  // ステータス更新(t_app_driver)
  //--------------------------------------
  $Driver = Model_AppDriver_Driver::query()
    ->where('identifier', $identifier)
    ->where('achieve_id', $achieve_id)
    ->get_one();
  $Driver->status = 1;
  $Driver->updated_at = date('c');
  $Driver->save();

  DB::commit_transaction();

} catch (Exception $e) {

  Log::error('[Exception]:'.$e->getLine() . ":" . $e->getMessage());

  DB::rollback_transaction();
  //--------------------------------------
  //	 異常終了
  //--------------------------------------
  Response::forge('', 404)->send(true);

  exit;
}

//--------------------------------------
//	 正常終了（ポイント付与完了）
//--------------------------------------			
echo 1;

/**
 * ユーザ認証を行う
 * @return boolean|string
 */
function authUser($uuid) {

  if(!$uuid) {
    return false;
  }		
  $user = Model_Client::query()->where('introduce_cd', $uuid)->get_one();
  if(!$user) {
    return false;
  }		

  return $user->id;
}


/**
 * ユーザ認証を行う
 * @return boolean|string
 */
function validate_user($identifier,&$user_id) {
  $user_id = authUser($identifier);
  if(!$user_id) {
    return false;
  }

  $user = Model_Client::find($user_id);
  if(!$user) {
    return false;
  }
  return true;
}

/**
 * ITEM購入
 * @return boolean
 */
function get_item($user_id,$reward_point = 0) {
  //
  // $rewart_point分の回復アイテムを付与する処理
  //
  return true;
}

/**
 * レコード件数の確認
 * @return レコード数
 */
function getRecCount($tablename,$key1,$key2,$status) {

  $q = null;
  $q = \DB::select(\DB::expr('COUNT(*) as cnt'))->from($tablename);
  $q->where('identifier', '=', $key1);
  $q->where('achieve_id', '=', $key2);
  if($status == true){
    $q->where('status', '=', $status);
  }

  $ret = $q->execute()->current();

  return $ret['cnt'];
}

/**
 * IPチェック
 */
function validate_remote_address($whitelist=null) {
  $ip = Input::real_ip();

  if(!$whitelist) {
    $whitelist = array(
      '59.106.111.156',
      '27.110.48.28',
      '59.106.111.152',
      '27.110.48.24',
      '10.0.2.2'
    );
  }

  if(in_array($ip, $whitelist)) {
    return true;
  }

  return false;
}

<?php

// キーとする項目以外はデフォルト空とする
$uid			= Input::get('uid');				// ユーザーID
$cid			= Input::get('cid');				// 広告ID
$cname			= Input::get('cname', '');			// 広告名
$carrier		= Input::get('carrier', '');		// キャリア
$click_date		= Input::get('click_date');			// クリック日時
$action_date	= Input::get('action_date');		// 成果発生日時
$amount			= Input::get('amount', '');			// 売上金額
$commission		= Input::get('commission', '');		// 報酬額
$aff_id			= Input::get('aff_id', '');			// アフィリエイトID
$point			= Input::get('point');				// 還元ポイント（付与ポイント）
$pid			= Input::get('pid');				// 成果地点ID
$action_id		= Input::get('action_id', '');		// 成果ID
$media_data		= Input::get('media_data', '');		// 媒体追加情報
$dateval		= date("c");

$user_id;

if(validate_remote_address() == false){
  Log::info('invalid_IP:'.json_encode(Input::real_ip()));
  Response::forge('', 500)->send(true);
  exit;
}

$ret = validate_user($uid, $user_id);
if($ret != true){
  Response::forge('', 400)->send(true);
  exit;
}

// ユーザーID、広告ID、	成果発生日時、成果地点IDがキーとなる
$count	= getRecCount('t_careward', $uid, $cid, $action_date, $pid, 1);

//--------------------------------------
// アイテム付与済、CARward指定のOKを返却
//--------------------------------------
if($count > 0){
    //--------------------------------------
    //	 正常終了（ポイント付与済）
    //--------------------------------------
    echo "OK";
    exit();
}



try {

  DB::start_transaction();

  $sql = <<<_SQL
    INSERT INTO t_careward (
      uid
      ,cid
      ,cname
      ,carrier
      ,click_date
      ,action_date
      ,amount
      ,commission
      ,aff_id
      ,point
      ,pid
      ,action_id
      ,media_data
      ,created_at
    )
    VALUES	 (
      :uid
      ,:cid
      ,:cname
      ,:carrier
      ,:click_date
      ,:action_date
      ,:amount
      ,:commission
      ,:aff_id
      ,:point
      ,:pid
      ,:action_id
      ,:media_data
      ,:created_at
    )
    _SQL
    ;

  $q = DB::query($sql)
    ->bind('uid', $uid)
    ->bind('cid', $cid)
    ->bind('cname', $cname)
    ->bind('carrier', $carrier)
    ->bind('click_date', $click_date)
    ->bind('action_date', $action_date)
    ->bind('amount', $amount)
    ->bind('commission', $commission)
    ->bind('aff_id', $aff_id)
    ->bind('point', $point)
    ->bind('pid', $pid)
    ->bind('action_id', $action_id)
    ->bind('media_data', $media_data)
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
  // ステータス更新(t_careward)
  //--------------------------------------
  $Careward = Model_Careward_Careward::query()
    ->where('uid', $uid)
    ->where('cid', $cid)
    ->where('action_date', $action_date)
    ->where('pid', $pid)
    ->get_one();
  $Careward->status = 1;
  $Careward->updated_at = date('c');
  $Careward->save();

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
echo "OK";

/**
 * ユーザ認証を行う
 * @return boolean|string
 */
function _authUser($uuid) {

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
function validate_user($identifier, &$user_id) {
  $user_id = $this->_authUser($identifier);
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
 * @param unknown $item_id
 * @return boolean|string
 */
function get_item($user_id, $reward_point = 0) {

  //
  // $rewart_point分の回復アイテムを付与する処理
  //

  return true;
}

/**
 * レコード件数の確認
 * @return レコード数
 */
function getRecCount($tablename, $key1, $key2, $key3, $key4, $status) {

  $q = null;
  $q = \DB::select(\DB::expr('COUNT(*) as cnt'))->from($tablename);
  $q->where('uid', '=', $key1);
  $q->where('cid', '=', $key2);
  $q->where('action_date', '=', $key3);
  $q->where('pid', '=', $key4);
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
      '202.234.38.240',
      '59.106.126.70',
      '59.106.126.73',
      '59.106.126.74'
    );
  }

  if(in_array($ip, $whitelist)) {
    return true;
  }

  return false;
}

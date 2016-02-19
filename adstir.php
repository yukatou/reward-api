<?php

// キーとする項目以外はデフォルト空とする
$type				=	Input::get('type');		// １日１回: top ビューア: read
$user_key   = Input::get('user_key') //ユーザの紹介コードが送られてくる
  $transaction_id	=	Input::get('transaction_id');
$currency		=	Input::get('currency'); // 特に使わない
$amount			=	Input::get('amount'); // ハート数
$dateval		=	date("c");

$user_id;


// IPの制限
if(validate_remote_address() == false){
  Log::info('invalid_IP:'.json_encode(Input::real_ip()));
  Response::forge('', 500)->send(true);
  exit;
}

// ユーザ認証
$ret = validate_user($user_key, $user_id);
if($ret != true){
  Response::forge('', 400)->send(true);
  exit;
}

$count	= getRecCount('t_adstir', $user_key, $transactions_id, 1);

//--------------------------------------
// アイテム付与済、CARward指定のOKを返却
//--------------------------------------
if($count > 0){
  //--------------------------------------
  //	 正常終了（ポイント付与済）
  //--------------------------------------
  echo "OK";
  exit;
}

//
// １日１回動画であれば、その日に動画をみている場合はスキップさせる
//
if ($type === "top") {

  // 
  // もう動画をみているか確認する処理
  //

  if ("見ていた場合") {
    // 何もしないで正常終了させる
    echo "OK";
    exit;
  }
}

try {

  DB::start_transaction();

  $sql = <<<_SQL
    INSERT INTO t_adstir (
      user_id
      ,type
      ,transaction_id
      ,amount
      ,currency
      ,created
    )
    VALUES	 (
      :user_id
      ,:type
      ,:transaction_id
      ,:amount
      ,:currency
      ,:created
    )
    _SQL
    ;

  $q = DB::query($sql)
    ->bind('user_id', $user_id)
    ->bind('type', $type)
    ->bind('transaction_id', $transaction_id)
    ->bind('amount', $amount)
    ->bind('currency', $currency)
    ->bind('created', $dateval)
    ->execute()
    ;

  //--------------------------------------
  //④ アイテム付与（回復BOX赤固定）
  //--------------------------------------
  $ret = get_item($user_id,$point);

  if($ret != true){
    DB::rollback_transaction();
    Response::forge('', 400)->send(true);
    exit;
}

//--------------------------------------
// １日１回の動画視聴を更新
//--------------------------------------
if ($type === 'top') {
  //
  // app_usersのwatch_ad_dateに日付をいれる処理
  //
}

//--------------------------------------
//⑤ ステータス更新(t_adsir)
//--------------------------------------
$adstir = Model_Adstir::query()
  ->where('user_id', $user_id)
  ->where('transaction_id', $transaction_id)
  ->get_one();
$Careward->status = 1;
$Careward->modified = date('c');
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
function authUser($uuid) {

  if(!$uuid) {
    return false;
}
$user = Model_Client::query()->where('introduce_code', $uuid)->get_one();
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
      // 未定なので最初は全通
    );
}

return true;
}

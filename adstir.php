<?php

/**
 * NOTE: 
 * Adstirは uidとtransaction_idで重複判定を行うこと
 * テーブルは２つ用意する （ユーザからのお問い合わせに対応するため）
 * ①  情報管理用テーブル t_adstir 付与した時のみinsert
 * ②  ポイントログテーブル t_adstir アクセスログとして使用
 *
 * レスポンスに関して、Adstirは特に指定がないのでAppDriverと同じようにしておく
 * ポイント付与成功時はレスポンスボディに1を返す
 * 0は成功だがポイント未付与
 * それ以外はエラーとなる
 */

$type				=	Input::get('type');		// １日１回: top ビューア: read
$uid        = Input::get('uid') //ユーザの紹介コードが送られてくる
$transaction_id	=	Input::get('transaction_id');
$currency		=	Input::get('currency'); // 特に使わない
$amount			=	Input::get('amount'); // 付与するハート数
$dateval		=	date("c");

$user_id;

// アクセス元のIP制御
if (validate_remote_address() == false) {
  Log::info('invalid_IP:'.json_encode(Input::real_ip()));
  Response::forge('', 500)->send(true);
  exit;
}

// ユーザ認証
$ret = validate_user($uid, $user_id);
if ($ret != true) {
  Response::forge('', 400)->send(true);
  exit;
}

$count_adstir	= $this->_getRecCount('t_adstir',$uid, $transaction_id);
$count_adstir_log	= $this->_getRecCount('t_adstir_log',$uid, $transaction_id); 

try {

  DB::start_transaction();
  //--------------------------------------
  //①	t_adstir_log insert
  //	ログは毎回登録
  //--------------------------------------
  $sql = <<<_SQL
    INSERT INTO t_adstir_log (
      uid
      ,type
      ,transaction_id
      ,currency
      ,amount
      ,created
    )
    VALUES	 (
      :uid
      ,:type
      ,:transaction_id
      ,:currency
      ,:amount
      ,:created
    )
    _SQL
    ;

  $q = DB::query($sql)
    ->bind('transaction_id', $transaction_id)
    ->bind('type', $type)
    ->bind('currency', $currency)
    ->bind('amount', $amount)
    ->bind('created', $dateval)
    ->execute()
    ;


  //--------------------------------------
  // アイテム付与済の場合は0を返却
  //--------------------------------------
  if($count_adstir > 0){
    if($count_adstir_log != 0){
      //ログのみコミット
      DB::commit_transaction();
      //--------------------------------------
      //	 正常終了（ポイント付与済）
      //--------------------------------------			
      echo 0;
      exit();
    } else {
      //このルートの場合は、t_adstirにレコードがあるが、ステータスが未完了で終了した場合
      //従って、処理は続行させる。
    }
  }

  //--------------------------------------
  // １日１回動画であれば、その日に動画をみている場合はスキップさせる
  //--------------------------------------
  if ($type === "top") {

    // 
    // もう動画をみているか確認する処理
    //

    if ("見ていた場合") {
      // 何もしないで正常終了させる
      echo 0;
      exit;
    }
  }


  //--------------------------------------
  // t_adstir insert
  //--------------------------------------
  $sql = <<<_SQL
    INSERT INTO t_adstir (
      uid
      ,type
      ,transaction_id
      ,amount
      ,created
    )
    VALUES	 (
      :uid
      ,:type
      ,:transaction_id
      ,:amount
      ,:created
    )
    _SQL
    ;
  try {

    $q = DB::query($sql)
      ->bind('uid', $uid)
      ->bind('transaction_id', $transaction_id)
      ->bind('amount', $amount)
      ->bind('created', $dateval)
      ->execute()
      ;

  } catch (Exception $e) {
    // Log::info(json_encode(DB::error_info()));
    $errorinfo = DB::error_info();
    if($errorinfo[1] == 1062){
      //重複はスルー
    } else {
      //その他例外
      DB::rollback_transaction();
      Response::forge('', 400)->send(true);
      exit;
    }
  }

  //--------------------------------------
  //④ アイテム付与
  //--------------------------------------
  $ret = get_item($user_id,$amount);

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
function getRecCount($tablename,$uid,$transaction_id) {

  $q = null;
  $q = \DB::select(\DB::expr('COUNT(*) as cnt'))->from($tablename);
  $q->where('uid', '=', $uid);
  $q->where('transaction_id', '=', $transaction_id);

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

  if(in_array($ip, $whitelist)) {
    return true;
  }

  return false;
}

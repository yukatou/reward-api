<?php

/**
 * NOTE: 
 * AppDriverは identifierと成果IDと成果地点ID で重複判定を行うこと
 * テーブルは２つ用意する （ユーザからのお問い合わせに対応するため）
 * ①  情報管理用テーブル t_app_driver  付与した時のみinsert
 * ②  ポイントログテーブル t_app_driver_log  アクセスログとして使用
 *
 * ポイント付与成功時はレスポンスボディに1を返す
 * 0は成功だがポイント未付与
 * それ以外はエラーとなる
 */

$identifier				=	input::get('identifier'); //ユーザの紹介コードが送られてくる
$achieve_id				=	input::get('achieve_id'); // 成果ID
$accepted_time    =	input::get('accepted_time'); // 成果が発生した日時
$campaign_name    =	input::get('campaign_name'); // 広告につけられている名前
$advertisement_id =	input::get('advertisement_id'); // 成果地点ID
$advertisement_name =	input::get('advertisement_name'); // 成果地点に付けられている名前
$point					=	input::get('point'); // 付与するハート数
$payment				=	input::get('payment'); // 報酬金額
$dateval				=	date("c");

$user_id;

// アクセス元のIP制御
if (validate_remote_address() == false) {
  Log::info('invalid_IP:'.json_encode(Input::real_ip()));
  Response::forge('', 500)->send(true);
  exit;
}

// ユーザ認証
$ret = validate_user($identifier,$user_id);
if ($ret != true) {
  Response::forge('', 400)->send(true);
  exit;
}

$count_driver	= $this->_getRecCount('t_app_driver',$identifier,$achieve_id, $advertisement_id, 1);
$count_driver_log	= $this->_getRecCount('t_app_driver_log',$identifier,$achieve_id, $advertisement_id, 1);


try {

  DB::start_transaction();
  //--------------------------------------
  //①	t_app_driver_log insert
  //	ログは毎回登録
  //--------------------------------------
  $sql = <<<_SQL
    INSERT INTO t_app_driver_log (
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
  //② アイテム付与済、アドウェイズ指定の0を返却
  //--------------------------------------
  if($count_driver > 0){
    if($count_driver_log != 0){
      //ログのみコミット
      DB::commit_transaction();
      //--------------------------------------
      //	 正常終了（ポイント付与済）
      //--------------------------------------			
      echo 0;
      exit();
    } else {
      //このルートの場合は、t_app_driverにレコードがあるが、ステータスが未完了で終了した場合
      //従って、処理は続行させる。
    }
  }

  //--------------------------------------
  //③ t_app_driver insert
  //--------------------------------------
  $sql = <<<_SQL
    INSERT INTO t_app_driver (
      identifier
      ,achieve_id
      ,advertisement_id
      ,point
      ,created_at
    )
    VALUES	 (
      :identifier
      ,:achieve_id
      ,:advertisement_id
      ,:point
      ,:created_at
    )
    _SQL
    ;
  try {

    $q = DB::query($sql)
      ->bind('identifier', $identifier)
      ->bind('achieve_id', $achieve_id)
      ->bind('advertisement_id', $advertisement_id)
      ->bind('point',      $point)
      ->bind('created_at', $dateval)
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
  //④ アイテム付与（回復BOX赤固定）
  //--------------------------------------
  $ret = get_item($user_id,$point);

  if($ret != true){
    DB::rollback_transaction();
    Response::forge('', 400)->send(true);
    exit;
  }

  //--------------------------------------
  //⑤ ステータス更新(t_app_driver)
  //--------------------------------------
  $Driver = Model_AppDriver_Driver::query()
    ->where('identifier', $identifier)
    ->where('achieve_id', $achieve_id)
    ->where('advertisement_id', $advertisement_id)
    ->get_one();
  $Driver->status = 1;
  $Driver->updated_at = date('c');
  $Driver->save();

  //--------------------------------------
  //⑥ ステータス更新(t_app_driver_log)
  //--------------------------------------
  $Driver_Log = Model_AppDriver_DriverLog::query()
    ->where('identifier', $identifier)
    ->where('achieve_id', $achieve_id)
    ->where('advertisement_id', $advertisement_id)
    ->where('created_at', $dateval)
    ->get_one();
  $Driver_Log->status = 1;
  $Driver_Log->updated_at = date('c');
  $Driver_Log->save();

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
function getRecCount($tablename,$user_id,$archive_id,$advertisement_id,$status) {

  $q = null;
  $q = \DB::select(\DB::expr('COUNT(*) as cnt'))->from($tablename);
  $q->where('user_id', '=', $user_id);
  $q->where('achieve_id', '=', $archive_id);
  $q->where('advertisement_id', '=', $advertisement_id);

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

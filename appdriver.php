<?php
use Fuel\Core\Input;
class Controller_Api_Driver extends Controller_Api_Json {
	// 対応状態
	const YET_VALUE = '0';		// 未
	const DONE_VALUE = '1';		// 済

	public function __construct($request) {
		parent::__construct($request);
	}


	/**
	 * IPチェック
	 */
	private static function _validate_remote_address($whitelist=null) {
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
	

	/**
	 * データ移行状態を取得する（新端末）
	 */
	public function get_appdriver() {

		$identifier				=	Input::get('identifier');		//ユーザの紹介コードが送られてくる
		$achieve_id				=	Input::get('achieve_id');
		$accepted_time                          =	Input::get('accepted_time');
		$campaign_name                          =	Input::get('campaign_name');
		$advertisement_id                       =	Input::get('advertisement_id');
		$advertisement_name                     =	Input::get('advertisement_name');
		$point					=	Input::get('point');
		$payment				=	Input::get('payment');
		// $dateval				=	date("Y-m-d H:i:s");
		$dateval				=	date("c");

		$user_id;

		if($this->_validate_remote_address() == false){
Log::info('invalid_IP:'.json_encode(Input::real_ip()));
			Response::forge('', 500)->send(true);
		 	exit;
		}

		$ret = $this->_validate_user($identifier,$user_id);
		if($ret != true){
		 	Response::forge('', 400)->send(true);
		 	exit;
		}

		$count_driver 			= $this->_getRecCount('t_app_driver',$identifier,$achieve_id,1);
		$count_driver_log 		= $this->_getRecCount('t_app_driver_log',$identifier,$achieve_id,1);

// Log::info('count_driver'.$count_driver);
// Log::info('count_driver_log'.$count_driver_log);

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
				,created_at
			)
			VALUES	 (
				 :identifier
				,:achieve_id
				,:created_at
			)
_SQL
;
			try {
	
				$q = DB::query($sql)
				->bind('identifier', $identifier)
				->bind('achieve_id', $achieve_id)
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
			$ret = $this->_get_item($user_id,$point);

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
			->where('created_at', $dateval)
			->get_one();
			$Driver_Log->status = 1;
			$Driver_Log->updated_at = date('c');
			$Driver_Log->save();

			DB::commit_transaction();

			//--------------------------------------
			//	 正常終了（ポイント付与完了）
			//--------------------------------------			
			echo 1;
		} catch (Exception $e) {
			Log::error('[Exception]:'.$e->getLine() . ":" . $e->getMessage());
			DB::rollback_transaction();
			//--------------------------------------
			//	 異常終了
			//--------------------------------------
			Response::forge('', 404)->send(true);
			exit;
		}

	}


	
	/**
	 * ユーザ認証を行う
	 * @return boolean|string
	 */
	private function _authUser($uuid) {
		
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
	private function _validate_user($identifier,&$user_id) {
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
	private function _get_item($user_id,$reward_point = 0) {

//		$item = Model_Item::find(4);
                $item = Model_Item::find(6);
		if(!$item) {
			return false;
		}

		$item_id = (int)$item['id'];
		$purchase = new Model_Purchase();
		$purchase->item_id = $item_id;
		$purchase->is_spent = 0;
		$purchase->user_client_id = $user_id;
		$purchase->item_kbn = 9;
                $purchase->reward_point = $reward_point;
		$purchase->save();

		return true;
	}

	/**
	 * レコード件数の確認
	 * @return レコード数
	 */
	private function _getRecCount($tablename,$key1,$key2,$status) {

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

}

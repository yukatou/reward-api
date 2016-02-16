<?php
use Fuel\Core\Input;
class Controller_Api_Careward extends Controller_Api_Json {
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


	/**
	 * ポイント反映処理
	 */
	public function get_careward() {
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

		if($this->_validate_remote_address() == false){
			Log::info('invalid_IP:'.json_encode(Input::real_ip()));
			Response::forge('', 500)->send(true);
		 	exit;
		}

		$ret = $this->_validate_user($uid, $user_id);
		if($ret != true){
		 	Response::forge('', 400)->send(true);
		 	exit;
		}

		// ユーザーID、広告ID、	成果発生日時、成果地点IDがキーとなる
		$count_careward		= $this->_getRecCount('t_careward', $uid, $cid, $action_date, $pid, 1);
		$count_careward_log	= $this->_getRecCount('t_careward_log', $uid, $cid, $action_date, $pid, 1);

// Log::info('count_careward'.$count_careward);
// Log::info('count_careward_log'.$count_careward_log);

		try {

			DB::start_transaction();
			//--------------------------------------
			//①	t_careward_log insert
			//	ログは毎回登録
			//--------------------------------------
			$sql = <<<_SQL
			INSERT INTO t_careward_log (
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
			//② アイテム付与済、CARward指定のOKを返却
			//--------------------------------------
			if($count_careward > 0){
				if($count_careward_log != 0){
					//ログのみコミット
					DB::commit_transaction();
					//--------------------------------------
					//	 正常終了（ポイント付与済）
					//--------------------------------------
					echo "OK";
					exit();
				} else {
					//このルートの場合は、t_carewardにレコードがあるが、ステータスが未完了で終了した場合
					//従って、処理は続行させる。
				}
			}

			//--------------------------------------
			//③ t_careward insert
			//--------------------------------------
			$sql = <<<_SQL
			INSERT INTO t_careward (
				 uid
				,cid
				,action_date
				,pid
				,created_at
			)
			VALUES	 (
				 :uid
				,:cid
				,:action_date
				,:pid
				,:created_at
			)
_SQL
;
			try {

				$q = DB::query($sql)
				->bind('uid', $uid)
				->bind('cid', $cid)
				->bind('action_date', $action_date)
				->bind('pid', $pid)
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
			//⑤ ステータス更新(t_careward)
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

			//--------------------------------------
			//⑥ ステータス更新(t_careward_log)
			//--------------------------------------
			$Careward_Log = Model_Careward_CarewardLog::query()
			->where('uid', $uid)
			->where('cid', $cid)
			->where('action_date', $action_date)
			->where('pid', $pid)
			->get_one();
			$Careward_Log->status = 1;
			$Careward_Log->updated_at = date('c');
			$Careward_Log->save();

			DB::commit_transaction();

			//--------------------------------------
			//	 正常終了（ポイント付与完了）
			//--------------------------------------
			echo "OK";
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
	private function _validate_user($identifier, &$user_id) {
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
	private function _get_item($user_id, $reward_point = 0) {

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
	private function _getRecCount($tablename, $key1, $key2, $key3, $key4, $status) {

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

}

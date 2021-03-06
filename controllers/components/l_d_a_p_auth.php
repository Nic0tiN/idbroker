<?php
App::import('Component', 'Auth');

class LDAPAuthComponent extends AuthComponent {

/**
 * setup the model stuff
 *
 *
 */
	function __construct(){
		$model = Configure::read('LDAP.LdapAuth.Model');
		$this->sqlUserModel = Configure::read('LDAP.LdapAuth.MirrorSQL.Users');
		$this->sqlGroupModel = Configure::read('LDAP.LdapAuth.MirrorSQL.Groups');
		if(isset($this->sqlUserModel) && !empty($this->sqlUserModel) ){
				$this->sqlUserModel =& $this->getModel($this->sqlUserModel);
		}
		if(isset($this->sqlGroupModel) && !empty($this->sqlGroupModel) ){
				$this->sqlGroupModel =& $this->getModel($this->sqlGroupModel);
		}
		$this->groupType = Configure::read('LDAP.groupType');
		//lets find all of our users groups
		if(!isset($this->groupType) || empty($this->groupType) ){
				$this->groupType = 'group';
		}
		$this->userModel = empty($model) ? 'Idbroker.LdapAuth' : $model;
		$this->model =& $this->getModel();
		parent::__construct();
	}


/**
 * The name of the model that represents users which will be authenticated.  Defaults to 'User'.
 *
 * @var string
 * @access public
 * @link http://book.cakephp.org/view/1266/userModel
 */
        var $userModel;
        var $sqlUserModel;
        var $sqlGroupModel;

/**
 * Main execution method.  Handles redirecting of invalid users, and processing
 * of login form data.
 *
 * @param object $controller A reference to the instantiating controller object
 * @return boolean
 * @access public
 */
	function startup(&$controller) {
		$isErrorOrTests = (
			strtolower($controller->name) == 'cakeerror' ||
			(strtolower($controller->name) == 'tests' && Configure::read() > 0)
		);
		if ($isErrorOrTests) {
			return true;
		}

		$methods = array_flip($controller->methods);
		$action = strtolower($controller->params['action']);
		$isMissingAction = (
			$controller->scaffold === false &&
			!isset($methods[$action])
		);

		if ($isMissingAction) {
			return true;
		}

		if (!$this->__setDefaults()) {
			return false;
		}

		$this->data = $controller->data;
		$url = '';

		if (isset($controller->params['url']['url'])) {
			$url = $controller->params['url']['url'];
		}
		$url = Router::normalize($url);
		$loginAction = Router::normalize($this->loginAction);

		$allowedActions = array_map('strtolower', $this->allowedActions);
		$isAllowed = (
			$this->allowedActions == array('*') ||
			in_array($action, $allowedActions)
		);

		if ($loginAction != $url && $isAllowed) {
			return true;
		}

		if ($loginAction == $url) {
			if (empty($controller->data) || !isset($controller->data[$this->model->alias])) {
				if (!$this->Session->check('Auth.redirect') && !$this->loginRedirect && env('HTTP_REFERER')) {
					$this->Session->write('Auth.redirect', $controller->referer(null, true));
				}
				return false;
			}

			$isValid = !empty($controller->data[$this->model->alias][$this->fields['username']]) &&
				!empty($controller->data[$this->model->alias][$this->fields['password']]);

			if ($isValid) {
				$username = $controller->data[$this->model->alias][$this->fields['username']];
				$password = $controller->data[$this->model->alias][$this->fields['password']];

				$data = array(
					$this->model->alias . '.' . $this->fields['username'] => $username,
					$this->model->alias . '.' . $this->fields['password'] => $password
				);

				if ($this->login($data)) {
					if ($this->autoRedirect) {
						$controller->redirect($this->redirect(), null, true);
					}
					return true;
				}
				
			}

			$this->Session->setFlash($this->loginError, $this->flashElement, array(), 'auth');
			$controller->data[$this->model->alias][$this->fields['password']] = null;
			return false;
		} else {
			if (!$this->user()) {
				if (!$this->RequestHandler->isAjax()) {
					$this->Session->setFlash($this->authError, $this->flashElement, array(), 'auth');
					if (!empty($controller->params['url']) && count($controller->params['url']) >= 2) {
						$query = $controller->params['url'];
						unset($query['url'], $query['ext']);
						$url .= Router::queryString($query, array());
					}
					$this->Session->write('Auth.redirect', $url);
					$controller->redirect($loginAction);
					return false;
				} elseif (!empty($this->ajaxLogin)) {
					$controller->viewPath = 'elements';
					echo $controller->render($this->ajaxLogin, $this->RequestHandler->ajaxLayout);
					$this->_stop();
					return false;
				} else {
					$controller->redirect(null, 403);
				}
			}
		}

		if (!$this->authorize) {
			return true;
		}

		extract($this->__authType());
		switch ($type) {
			case 'controller':
				$this->object =& $controller;
			break;
			case 'crud':
			case 'actions':
				if (isset($controller->LDAPAcl)) {
					$this->LDAPAcl =& $controller->LDAPAcl;
				} else {
					trigger_error(__('Could not find LDAPAclComponent. Please include LDAPAcl in Controller::$components.', true), E_USER_WARNING);
				}
			break;
			case 'model':
				if (!isset($object)) {
					$hasModel = (
						isset($controller->{$controller->modelClass}) &&
						is_object($controller->{$controller->modelClass})
					);
					$isUses = (
						!empty($controller->uses) && isset($controller->{$controller->uses[0]}) &&
						is_object($controller->{$controller->uses[0]})
					);

					if ($hasModel) {
						$object = $controller->modelClass;
					} elseif ($isUses) {
						$object = $controller->uses[0];
					}
				}
				$type = array('model' => $object);
			break;
		}

		if ($this->isAuthorized($type)) {
			return true;
		}

		$this->Session->setFlash($this->authError, $this->flashElement, array(), 'auth');
		$controller->redirect($controller->referer(), null, true);
		return false;
	}


/**
 * Determines whether the given user is authorized to perform an action.  The type of
 * authorization used is based on the value of AuthComponent::$authorize or the
 * passed $type param.
 *
 * Types:
 * 'controller' will validate against Controller::isAuthorized() if controller instance is
 * 				passed in $object
 * 'actions' will validate Controller::action against an LDAPAclComponent::check()
 * 'crud' will validate mapActions against an LDAPAclComponent::check()
 * 		array('model'=> 'name'); will validate mapActions against model
 * 		$name::isAuthorized(user, controller, mapAction)
 * 'object' will validate Controller::action against
 * 		object::isAuthorized(user, controller, action)
 *
 * @param string $type Type of authorization
 * @param mixed $object object, model object, or model name
 * @param mixed $user The user to check the authorization of
 * @return boolean True if $user is authorized, otherwise false
 * @access public
 */
	function isAuthorized($type = null, $object = null, $user = null) {
		if (empty($user) && !$this->user()) {
			return false;
		} elseif (empty($user)) {
			$user = $this->user();
		}

		extract($this->__authType($type));

		if (!$object) {
			$object = $this->object;
		}

		$valid = false;
		switch ($type) {
			case 'controller':
				$valid = $object->isAuthorized();
			break;
			case 'actions':
				$valid = $this->LDAPAcl->check($user, $this->action());
			break;
			case 'crud':
				if (!isset($this->actionMap[$this->params['action']])) {
					trigger_error(
						sprintf(__('Auth::startup() - Attempted access of un-mapped action "%1$s" in controller "%2$s"', true), $this->params['action'], $this->params['controller']),
						E_USER_WARNING
					);
				} else {
					$valid = $this->LDAPAcl->check(
						$user,
						$this->action(':controller'),
						$this->actionMap[$this->params['action']]
					);
				}
			break;
			case 'model':
				$action = $this->params['action'];
				if (isset($this->actionMap[$action])) {
					$action = $this->actionMap[$action];
				}
				if (is_string($object)) {
					$object = $this->getModel($object);
				}
			case 'object':
				if (!isset($action)) {
					$action = $this->action(':action');
				}
				if (empty($object)) {
					trigger_error(sprintf(__('Could not find %s. Set AuthComponent::$object in beforeFilter() or pass a valid object', true), get_class($object)), E_USER_WARNING);
					return;
				}
				if (method_exists($object, 'isAuthorized')) {
					$valid = $object->isAuthorized($user, $this->action(':controller'), $action);
				} elseif ($object) {
					trigger_error(sprintf(__('%s::isAuthorized() is not defined.', true), get_class($object)), E_USER_WARNING);
				}
			break;
			case null:
			case false:
				return true;
			break;
			default:
				trigger_error(__('Auth::isAuthorized() - $authorize is set to an incorrect value.  Allowed settings are: "actions", "crud", "model" or null.', true), E_USER_WARNING);
			break;
		}
		return $valid;
	}




/**
 * Identifies a user based on specific criteria.
 *
 * @param mixed $user Optional. The identity of the user to be validated.
 *              Uses the current user session if none specified.
 * @param array $conditions Optional. Additional conditions to a find.
 * @return array User record data, or null, if the user could not be identified.
 * @access public
 */
	function identify($user = null, $conditions = null) {
		if ($conditions === false) {
			$conditions = null;
		} elseif (is_array($conditions)) {
			$conditions = array_merge((array)$this->userScope, $conditions);
		} else {
			$conditions = $this->userScope;
		}
		if (empty($user)) {
			$user = $this->user();
			if (empty($user)) {
				return null;
			}
		} elseif (is_object($user) && is_a($user, 'Model')) {
			if (!$user->exists()) {
				return null;
			}
			$user = $user->read();
			$user = $user[$this->model->alias];
		} elseif (is_array($user) && isset($user[$this->model->alias])) {
			$user = $user[$this->model->alias];
		}

		if (is_array($user) && (isset($user[$this->fields['username']]) || isset($user[$this->model->alias . '.' . $this->fields['username']]))) {
			if (isset($user[$this->fields['username']]) && !empty($user[$this->fields['username']])  && !empty($user[$this->fields['password']])) {
				if (trim($user[$this->fields['username']]) == '=' || trim($user[$this->fields['password']]) == '=') {
					return false;
				}
				$username = $user[$this->fields['username']];
				$password = $user[$this->fields['password']];

			} elseif (isset($user[$this->model->alias . '.' . $this->fields['username']]) && !empty($user[$this->model->alias . '.' . $this->fields['username']])) {
				if (trim($user[$this->model->alias . '.' . $this->fields['username']]) == '=' || trim($user[$this->model->alias . '.' . $this->fields['password']]) == '=') {
					return false;
				}
				$username = $user[$this->model->alias . '.' . $this->fields['username']];
				$password = $user[$this->model->alias . '.' . $this->fields['password']];
			} else {
				return false;
			}
			$dn = $this->getDn($this->model->primaryKey, $username);
			$loginResult = $this->ldapauth($dn, $password);
			if( $loginResult == 1){
				$user = $this->model->find('all', array('scope'=>'base', 'targetDn'=>$dn));
				$data = $user[0];

				$data[$this->model->alias]['bindDN'] = $dn;
				$data[$this->model->alias]['bindPasswd'] = $password;
				$groups = $this->getGroups($data[$this->model->alias]);
				if(isset($this->sqlUserModel) && !empty($this->sqlUserModel)){
					$userRecord = $this->existsOrCreateSQLUser($data);
					if($userRecord){
						$this->Session->write('Auth.'.$this->model->alias.'Groups',$groups);
						//Check if we are mirroring sql for groups
						if(isset($this->sqlGroupModel) && !empty($this->sqlGroupModel)){
							if($sqlGroup = $this->existsOrCreateSQLGroup($userRecord,$groups)){
								$this->Session->write('Auth.Groups',$sqlGroup);
							}else{
								$this->log("Failed to update sql mirrored groups:".print_r($sqlGroup,1),'ldap.error');
							}
						}
						//Stuff The Sql User Record in the session just like the AuthLdap
						$this->Session->write('Auth.'.$this->sqlUserModel->alias, $userRecord);
					}else{
						$this->log("Error creating or finding the SQL version of the user: ".print_r($data,1),'ldap.error');
					}
				}
			}else{
				$this->Session->setFlash(__('Invalid Username or Password, Please try again.', true), 'default',array('class'=>'error-message'));
				$this->loginError =  $loginResult;
				return false;
			}

			if (empty($data) || empty($data[$this->model->alias])) {
				return null;
			}
		} elseif (!empty($user) && is_string($user)) {
			$data = $this->model->find('first', array(
				'conditions' => array_merge(array($this->model->escapeField() => $user), $conditions),
			));
			if (empty($data) || empty($data[$this->model->alias])) {
				return null;
			}
		}

		if (!empty($data)) {
			if (!empty($data[$this->model->alias][$this->fields['password']])) {
				unset($data[$this->model->alias][$this->fields['password']]);
			}
			return $data[$this->model->alias];
		}
		return null;
	}


/**
 * Validates a user against an abstract object.
 *
 * @param mixed $object  The object to validate the user against.
 * @param mixed $user    Optional.  The identity of the user to be validated.
 *                       Uses the current user session if none specified.  For
 *                       valid forms of identifying users, see
 *                       AuthComponent::identify().
 * @param string $action Optional. The action to validate against.
 * @see AuthComponent::identify()
 * @return boolean True if the user validates, false otherwise.
 * @access public
 */
        function validate($object, $user = null, $action = null) {
                if (empty($user)) {
                        $user = $this->user();
                }
                if (empty($user)) {
                        return false;
                }
                return $this->LDAPAcl->check($user, $object, $action);
        }


        function ldapauth($dn, $password){
                $authResult =  $this->model->auth( array('dn'=>$dn, 'password'=>$password));
                return $authResult;
        }

        function getDn( $attr, $query){
                $userObj = $this->model->find('all', array('conditions'=>"$attr=$query", 'scope'=>'sub'));
                return($userObj[0][$this->model->alias]['dn']);
        }

	function existsOrCreateSQLGroup($user = null, $groups = null){
		if(!$user || !$groups) return false;
		
		$parent_id = Configure::read('LDAP.Group.behavior.permissionable.parent_id');

		$sqlGroups = array();
		foreach($groups as $groupName=>$dn){
			//If group already exists, add it to our groups list and next
			if($sqlGroup = $this->sqlGroupModel->find('first',array('conditions'=>array('Group.name'=>$groupName)))){
				//group exists, see if user already listed in group, if not add them to sql group
				if(isset($user['username']) && is_string($user['username']) ){
					$userInGroup = false; //Lets see if we find out user
					foreach($sqlGroup[$this->sqlUserModel->alias] as $u){
						if(strtolower($u['username']) == strtolower($user['username'])){
							$sqlGroups[] = $sqlGroup[$this->sqlGroupModel->alias];
						}		
					}
					if(!$userInGroup){//User wasn't found, add him real quick
						$udata[$this->sqlUserModel->alias] = $user;
						if(!isset($udata[$this->sqlGroupModel->alias]) || 
						!in_array($sqlGroup[$this->sqlGroupModel->alias]['id'],$udata[$this->sqlGroupModel->alias]))
							$udata[$this->sqlGroupModel->alias][] = $sqlGroup[$this->sqlGroupModel->alias]['id'];
					}
				}
			}else{ //sqlGroup doesn't exists yet, so create them and add this user to it
				//todo add group
				$data[$this->sqlGroupModel->alias]['name'] = $groupName;
				$data[$this->sqlGroupModel->alias]['dn']  = $dn;
				if(isset($parent_id) && !empty($parent_id) ){
					$data[$this->sqlGroupModel->alias]['parent_id']  = $parent_id;
				}
				$udata[$this->sqlUserModel->alias] = $user;
				if(!isset($udata[$this->sqlGroupModel->alias]) ||  !in_array($this->sqlGroupModel->id,$udata[$this->sqlGroupModel->alias]))
					$udata[$this->sqlGroupModel->alias][] = $this->sqlGroupModel->id;
					
				if($ngroup = $this->sqlGroupModel->saveAll($data)){
					$this->log("Added new group {$groupName} with user {$user['username']}",'debug');
				}else{
					$this->log("Failed to add new group {$groupName}/{$dn} with user:". print_r($user,1).	
						':Input:'.print_r($data,1).':Result:'.print_r($ngroup,1),'ldap.error');
				}
	
			}
		}
		if($gupdate = $this->sqlUserModel->save($udata)){
			$this->log("Updating group {$sqlGroup[$this->sqlGroupModel->alias]['name']} to add:".print_r($user,1).
				':With Data:'.print_r($udata,1).':Result:'.print_r($gupdate,1),'ldap.debug');
		}else{
			$this->log("Failed to Mirror group {$sqlGroup[$this->sqlGroupModel->alias]['name']} for user:".
				print_r($user,1),'ldap.error');
		}
		$this->log("SQL group result:".print_r($sqlGroups,1).':'.print_r($user,1).':'.print_r($groups,1),'debug');
		return (!empty($sqlGroups)) ? $sqlGroups : false;
	}


	function existsOrCreateSQLUser($user){
		//Find out what the LDAP primary key is for the user model, this will be used to know which attribute to lookup the username in
		$userPK = $this->model->primaryKey;
		if(isset($user[$this->model->alias][$userPK]) ) $username = $user[$this->model->alias][$userPK];
		if(is_array($username) && isset($username[0]) && !is_array($username[0]) ) $username = $username[0];

		//Lets See if that username is already in our system
		$result = $this->sqlUserModel->find('first',array('recursive'=>-1,'conditions'=>array('username'=>$username)));
		//If so, lets just return that record and continue working
		if(isset($result)){
			//Check if we are mirroring groups as well, then refresh them
			return $result[$this->sqlUserModel->alias];
		}
		else{
			$this->sqlUserModel->create(); //User doesn't exists, grab it from the auth session and add it to the user table
			if(isset($user['displayname']) && !empty($user['displayname'])) $u['displayname'] = $user['displayname'];
			if(isset($user['dn']) && !empty($user['dn']) ) $u['dn'] = $user['dn'];
			if(isset($username) && !empty($username) ) $u['username'] = $username;
			if(isset($user['mail']) && !empty($user['mail']) ) $u['email'] = $user['mail'];
			//so that it will get a id number for the foreign keys
			if($this->sqlUserModel->save($u)){
				$result = $this->sqlUserModel->find('first', array('recursive'=>-1,'conditions'=>array('username'=>$username)));
				if($result){
					return $result[$this->sqlModel->alias];
				}else return false;
			}else{
				return false;
			}
		}
	}

	function getGroups($user = null){

		if(strtolower($this->groupType) == 'group'){
			$this->log("Looking for {$user['dn']} & 'objectclass'=>'group'",'ldap.debug');
                        $groups = $this->model->find('all',array('conditions'=>array('AND'=>array('objectclass'=>'group', 'member'=>$user['dn'])),'scope'=>'sub'));
		}elseif(strtolower($this->groupType) == 'groupofuniquenames'){
                        $groups = $this->model->find('all',array('conditions'=>array('AND'=>
				array('objectclass'=>'groupofuniquenames', 'uniquemember'=>$user['dn'])),'scope'=>'sub'));
		}elseif(strtolower($this->groupType) == 'posixgroup'){
			$pk = Configure::read('LDAP.User.Identifier');//either uid, cn, or samaccountname
                        $groups = $this->model->find('all',array('conditions'=>array('AND'=>array('objectclass'=>'posixgroup', 'memberuid'=>$user[$pk])),'scope'=>'sub'));
		}


		$groupIdentifer = Configure::read('LDAP.Group.Identifier');
		$groupIdentifer = (empty($groupIdentifer)) ? 'cn' : $groupIdentifer;
		foreach($groups as $group){
			$gid = $group[$this->model->alias][$groupIdentifer];
			if(isset($gid)){
				$mygroup[$gid] = $group[$this->model->alias]['dn'];
			}
		}
		//todo loop through groupos to see if any are nested groups that need to be expanded!
		$this->log("User was found in the following groups:".print_r($groups,1),'ldap.debug');
		return $mygroup;
	}

}
?>

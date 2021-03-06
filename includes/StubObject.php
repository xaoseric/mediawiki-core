<?php
/**
 * Delayed loading of global objects.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301, USA.
 * http://www.gnu.org/copyleft/gpl.html
 *
 * @file
 */

/**
 * Class to implement stub globals, which are globals that delay loading the
 * their associated module code by deferring initialisation until the first
 * method call.
 *
 * Note on reference parameters:
 *
 * If the called method takes any parameters by reference, the __call magic
 * here won't work correctly. The solution is to unstub the object before
 * calling the method.
 *
 * Note on unstub loops:
 *
 * Unstub loops (infinite recursion) sometimes occur when a constructor calls
 * another function, and the other function calls some method of the stub. The
 * best way to avoid this is to make constructors as lightweight as possible,
 * deferring any initialisation which depends on other modules. As a last
 * resort, you can use StubObject::isRealObject() to break the loop, but as a
 * general rule, the stub object mechanism should be transparent, and code
 * which refers to it should be kept to a minimum.
 */
class StubObject {
	/** @var null|string */
	protected $global;

	/** @var null|string */
	protected $class;

	/** @var array */
	protected $params;

	/**
	 * Constructor.
	 *
	 * @param string $global Name of the global variable.
	 * @param string $class Name of the class of the real object.
	 * @param array $params Parameters to pass to constructor of the real object.
	 */
	function __construct( $global = null, $class = null, $params = array() ) {
		$this->global = $global;
		$this->class = $class;
		$this->params = $params;
	}

	/**
	 * Returns a bool value whenever $obj is a stub object. Can be used to break
	 * a infinite loop when unstubbing an object.
	 *
	 * @param object $obj Object to check.
	 * @return bool True if $obj is not an instance of StubObject class.
	 */
	static function isRealObject( $obj ) {
		return is_object( $obj ) && !$obj instanceof StubObject;
	}

	/**
	 * Unstubs an object, if it is a stub object. Can be used to break a
	 * infinite loop when unstubbing an object or to avoid reference parameter
	 * breakage.
	 *
	 * @param object $obj Object to check.
	 * @return void
	 */
	static function unstub( $obj ) {
		if ( $obj instanceof StubObject ) {
			$obj->_unstub( 'unstub', 3 );
		}
	}

	/**
	 * Function called if any function exists with that name in this object.
	 * It is used to unstub the object. Only used internally, PHP will call
	 * self::__call() function and that function will call this function.
	 * This function will also call the function with the same name in the real
	 * object.
	 *
	 * @param string $name Name of the function called
	 * @param array $args Arguments
	 * @return mixed
	 */
	function _call( $name, $args ) {
		$this->_unstub( $name, 5 );
		return call_user_func_array( array( $GLOBALS[$this->global], $name ), $args );
	}

	/**
	 * Create a new object to replace this stub object.
	 * @return object
	 */
	function _newObject() {
		return MWFunction::newObj( $this->class, $this->params );
	}

	/**
	 * Function called by PHP if no function with that name exists in this
	 * object.
	 *
	 * @param string $name Name of the function called
	 * @param array $args Arguments
	 * @return mixed
	 */
	function __call( $name, $args ) {
		return $this->_call( $name, $args );
	}

	/**
	 * This function creates a new object of the real class and replace it in
	 * the global variable.
	 * This is public, for the convenience of external callers wishing to access
	 * properties, e.g. eval.php
	 *
	 * @param string $name Name of the method called in this object.
	 * @param int $level Level to go in the stack trace to get the function
	 *   who called this function.
	 * @throws MWException
	 */
	function _unstub( $name = '_unstub', $level = 2 ) {
		static $recursionLevel = 0;

		if ( !$GLOBALS[$this->global] instanceof StubObject ) {
			return $GLOBALS[$this->global]; // already unstubbed.
		}

		if ( get_class( $GLOBALS[$this->global] ) != $this->class ) {
			$fname = __METHOD__ . '-' . $this->global;
			wfProfileIn( $fname );
			$caller = wfGetCaller( $level );
			if ( ++$recursionLevel > 2 ) {
				wfProfileOut( $fname );
				throw new MWException( "Unstub loop detected on call of "
					. "\${$this->global}->$name from $caller\n" );
			}
			wfDebug( "Unstubbing \${$this->global} on call of "
				. "\${$this->global}::$name from $caller\n" );
			$GLOBALS[$this->global] = $this->_newObject();
			--$recursionLevel;
			wfProfileOut( $fname );
		}
	}
}

/**
 * Stub object for the content language of this wiki. This object have to be in
 * $wgContLang global.
 *
 * @deprecated since 1.18
 */
class StubContLang extends StubObject {

	function __construct() {
		wfDeprecated( __CLASS__, '1.18' );
		parent::__construct( 'wgContLang' );
	}

	function __call( $name, $args ) {
		return $this->_call( $name, $args );
	}

	/**
	 * @return Language
	 */
	function _newObject() {
		global $wgLanguageCode;
		$obj = Language::factory( $wgLanguageCode );
		$obj->initEncoding();
		$obj->initContLang();
		return $obj;
	}
}

/**
 * Stub object for the user language. It depends of the user preferences and
 * "uselang" parameter that can be passed to index.php. This object have to be
 * in $wgLang global.
 */
class StubUserLang extends StubObject {

	function __construct() {
		parent::__construct( 'wgLang' );
	}

	function __call( $name, $args ) {
		return $this->_call( $name, $args );
	}

	/**
	 * @return Language
	 */
	function _newObject() {
		return RequestContext::getMain()->getLanguage();
	}
}

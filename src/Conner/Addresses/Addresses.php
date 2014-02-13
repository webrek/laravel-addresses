<?php namespace Conner\Addresses;

use Illuminate\Database\Eloquent\Collection;
use Cache;

/**
 * Primary handler for managing addresses
 */
class Addresses {
	
	/**
	 * Create a new address using post array data
	 * 
	 * @param array $data
	 * @return object $address or null
	 */
	public function createAddress($data = null) {
		$user = \Sentry::getUser();
		if(is_null($data)) {
			$data = \Input::all();
		}

		$address = new Address($data);
		
		$address->user_id = $user->id;
		if($address->save()) {
			self::checkFlags($address);
			return $address;
		}
	}
	
	/**
	 * Create a new address using post array data
	 *
	 * @param object or id $address
	 * @param array $data
	 * @return object $address or null
	 */
	public function updateAddress($address, $data = null) {
		$user = \Sentry::getUser();
		if(is_null($data)) {
			$data = \Input::all();
		}
	
		if(!is_object($address)) {
			$address = Address::where('user_id', $user->id)
				->where('id', $address)
				->first();
		}
		
		if($address->update($data)) {
			self::checkFlags($address);
			return $address;
		}
	}

	/**
	 * Delete address. Will delete it if it can. This function does a check to make sure logged in
	 * user owns the address
	 *
	 * @param object or id $address
	 */
	public function deleteAddress($address) {
		$user = \Sentry::getUser();

		if(!is_object($address)) {
			$address = Address::where('user_id', $user->id)
			->where('id', $address)
			->first();
		}

		if($address->user_id == $user->id) {
			$address->delete();
		}
	}
	
	/**
	 * Return instance of Illuminate\Validation\Validator that is setup with Address rules and data (from html input)
	 * Addresses::getValidator()->fails(); // test input from user
	 * 
	 * @param array $input input array from user (or null to default to Input::all())
	 * @return Illuminate\Validation\Validator ready to test for fails|passes
	 */
	function getValidator($input = null) {
		$rules = Address::rules();
		
		if(is_null($input)) {
			$input = \Input::all();
		}
		
		$address = new Address($input);
		
		return \Validator::make($address->toArray(), $rules);
	}
	
	/**
	 * Return Collection of Addresses owned by the given userID.
	 * 
	 * @param Collection
	 */
	public function getAll($userId) {
		return Address::where('user_id', $userId)
			->orderBy('is_primary', 'DESC')
			->orderBy('is_shipping', 'DESC')
			->orderBy('is_billing', 'DESC')
			->orderBy('id', 'ASC')
			->get();
	}

	/**
	 * Return Collection of Addresses owned by the given userID
	 *
	 * @param Collection
	 */
	public function getPrimary($userId) {
		return Address::where('user_id', $userId)
		->where('is_primary', true)
		->first();
	}
	
	/**
	 * Return Collection of Addresses owned by the given userID
	 *
	 * @param Collection
	 */
	public function getBilling($userId) {
		return Address::where('user_id', $userId)
			->where('is_billing', true)
			->first();
	}
	
	/**
	 * Return Collection of Addresses owned by the given userID
	 *
	 * @param Collection
	 */
	public function getShipping($userId) {
		return Address::where('user_id', $userId)
			->where('is_shipping', true)
			->first();
	}
	
	/**
	 * Set primary address for the given user. Unsets all other addresses for that
	 * user as non-primary
	 *
	 * @param mixed $objectOrId primary address id or object instance
	 */
	public function setPrimary($address) {
		if(!is_object($address)) {
			$address = Address::find($address);
		}
		
		if($userId = $address->user_id) {
			Address::where('user_id', '=', $userId)->update(array('is_primary'=>false));
			$address->is_primary = true;
			$address->save();
		}
	}

	/**
	 * Set billing address for the given user. Unsets all other addresses for that
	 * user as non-billing
	 *
	 * @param mixed $objectOrId primary address id or object instance
	 */
	public function setBilling($address) {
		if(!is_object($address)) {
			$address = Address::find($address);
		}
	
		if($userId = $address->user_id) {
			Address::where('user_id', $userId)->update(array('is_billing'=>false));
			$address->is_billing = true;
			$address->save();
		}
	}

	/**
	 * Set shipping address for the given user. Unsets all other addresses for that
	 * user as non-shipping
	 *
	 * @param mixed $objectOrId primary address id or object instance
	 */
	public function setShipping($address) {
		if(!is_object($address)) {
			$address = Address::find($address);
		}
	
		if($userId = $address->user_id) {
			Address::where('user_id', $userId)->update(array('is_shipping'=>false));
			$address->is_shipping = true;
			$address->save();
		} else {
			throw new UserNotFoundException;
		}
	}
	
	/**
	 * Return collection of all countries
	 * 
	 * @return Collection
	 */
	public static function getCountries() {
		return Cache::rememberForever('addresses.countries', function() {
			return Country::orderBy('name', 'ASC')->get();
		});
	}

	/**
	 * Return collection of all states/provinces within a country
	 * TODO: caching to make this fetch speedy speedy
	 *
	 * @param string 2 letter country alpha-code
	 * @return Collection
	 */
	public static function getStates($countryA2 = 'US') {
		if(strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}
		
		return Cache::rememberForever('addresses.'.$countryA2.'.states', function() use ($countryA2) {
			return State::where('country_a2', $countryA2)->orderBy('name', 'ASC')->get();
		});
	}
	
	/**
	 * Accept 2 or 3 digit alpha-code
	 * 
	 * @param string $countryA2
	 * @return $string full country name
	 */
	public static function countryName($countryA2) {
		if(strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}

		return Cache::rememberForever('addresses.'.$countryA2.'.country_name', function() use ($countryA2) {
			return Country::byCode($countryA2)->first()->name;
		});
	}

	/**
	 * Accept 2 digit alpha-code. Pass in the country to be extra sure you get the right name returned.
	 * TODO: caching to make this fetch speedy speedy
	 *
	 * @param string $stateA2
	 * @param string $countryA2 defaults to 'US'
	 * @return $string full state/province name
	 */
	public static function stateName($stateA2, $countryA2 = 'US') {
		if(strlen($stateA2) != 2 || strlen($countryA2) != 2) {
			throw new InvalidValueException;
		}
		
		if(empty($countryA2)) {
			return State::byCode($code)->firstOrFail()->name;
		}

		return Cache::rememberForever('addresses.'.$countryA2.'.'.$stateA2.'.state_name', function() use ($stateA2, $countryA2) {
			return State::byCountry($countryA2)->byCode($stateA2)->firstOrFail()->name;
		});
	}

	/**
	 * Wrapper for \Form::select that populated the country list automatically
	 * Defaults to United States as selected
	 * 
	 * @param string $name
	 * @param string $selected
	 * @param array $options
	 */
	public function selectCountry($name, $selected = 'US', $options = array()) {
		$list = array();
		foreach (self::getCountries() as $country) {
			if($country->a2 == 'US') {
				$usa = $country;
			} else {
				$list[$country->a2] = $country->name;
			}
		}
		
		$list = array_merge(array('US'=>$usa->name), $list);

		return \Form::select($name, $list, $selected, $options);
	}
	
	/**
	 * Wrapper for \Form::select that populated the state/province list automatically
	 * Defaults to United States as selected
	 * 
	 * @param string $name
	 * @param string $selected
	 * @param array $options
	 *   $options['country'] = 'US'
	 */
	public function selectState($name, $selected = null, $options = array('country'=>'US')) {
		$list = array(''=>'');
		
		foreach (self::getStates($options['country']) as $state) {
			$list[$state->a2] = $state->name;
		}
		
		unset($options['country']);

		return \Form::select($name, $list, $selected, $options);
	}
	
	private function checkFlags($address) {
		$flags = \Config::get('addresses::flags');
		foreach($flags as $flag) {
			if($address->{'is_'.$flag}) {
				call_user_func('self::set'.ucfirst($flag), $address);
			}
		}
	}
	
}
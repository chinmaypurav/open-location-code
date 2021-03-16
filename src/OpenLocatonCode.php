<?php
namespace Chinmay\OpenLocationCode;
// Copyright 2014 Google Inc. All rights reserved.
//
// Licensed under the Apache License, Version 2.0 (the 'License');
// you may not use this file except in compliance with the License.
// You may obtain a copy of the License at
//
// http://www.apache.org/licenses/LICENSE-2.0
//
// Unless required by applicable law or agreed to in writing, software
// distributed under the License is distributed on an 'AS IS' BASIS,
// WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
// See the License for the specific language governing permissions and
// limitations under the License.

/**
 * Convert locations to and from short codes.
 *
 * Open Location Codes are short, 10-11 character codes that can be used instead
 * of street addresses. The codes can be generated and decoded offline, and use
 * a reduced character set that minimises the chance of codes including words.
 *
 * Codes are able to be shortened relative to a nearby location. This means that
 * in many cases, only four to seven characters of the code are needed.
 * To recover the original code, the same location is not required, as long as
 * a nearby location is provided.
 *
 * Codes represent rectangular areas rather than points, and the longer the
 * code, the smaller the area. A 10 character code represents a 13.5x13.5
 * meter area (at the equator. An 11 character code represents approximately
 * a 2.8x3.5 meter area.
 *
 * Two encoding algorithms are used. The first 10 characters are pairs of
 * characters, one for latitude and one for longitude, using base 20. Each pair
 * reduces the area of the code by a factor of 400. Only even code lengths are
 * sensible, since an odd-numbered length would have sides in a ratio of 20:1.
 *
 * At position 11, the algorithm changes so that each character selects one
 * position from a 4x5 grid. This allows single-character refinements.
 *
 * Examples:
 *
 *   Encode a location, default accuracy:
 *   $olc = new OpenLocationCode();
 * 
 *   echo "Encoded Value: " = $olc->encode(47.365590, 8.524997);
 *
 *   Encode a location using one stage of additional refinement:
 *   echo "Encoded Value with high precision: " . $olc->encode(47.365590, 8.524997, 11);
 *
 *   Decode a full code:
 *   $code = '8FVC9G8F+6XQ';
 *   $coord = $olc->decode($code);
 *   echo 'Center is ' + $olc->latitudeCenter + ',' + $olc->longitudeCenter;
 *
 *   Attempt to trim the first characters from a code:
 *   $shortCode = $olc->shorten('8FVC9G8F+6X', 47.5, 8.5);
 *
 *   Recover the full code from a short code:
 *   $code = $olc->recoverNearest('9G8F+6X', 47.4, 8.6);
 *   $code = $olc->recoverNearest('8F+6X', 47.4, 8.6);
 */

class OpenLocationCode {
    public $latitudeLo;
    public $longitudeLo;
    public $latitudeHi;
    public $longitudeHi;
    public $codeLength;
    public $latitudeCenter;
    public $longitudeCenter;
    /**
     * Provides a normal precision code, approximately 14x14 meters.
     * @const {number}
     */
    private $CODE_PRECISION_NORMAL = 10;

    /**
     * Provides an extra precision code, approximately 2x3 meters.
     * @const {number}
     */
    private $CODE_PRECISION_EXTRA = 11;

    // A separator used to break the code into two parts to aid memorability.
    private $SEPARATOR_ = '+';

    // The number of characters to place before the separator.
    private $SEPARATOR_POSITION_ = 8;

    // The character used to pad codes.
    private $PADDING_CHARACTER_ = '0';

    // The character set used to encode the values.
    private $CODE_ALPHABET_ = '23456789CFGHJMPQRVWX';

    // The base to use to convert numbers to/from.
    private $ENCODING_BASE_;

    // The maximum value for latitude in degrees.
    private $LATITUDE_MAX_ = 90;

    // The maximum value for longitude in degrees.
    private $LONGITUDE_MAX_ = 180;

    // The max number of digits to process in a plus code.
    private $MAX_DIGIT_COUNT_ = 15;

    // Maximum code length using lat/lng pair encoding. The area of such a
    // code is approximately 13x13 meters (at the equator), and should be suitable
    // for identifying buildings. This excludes prefix and separator characters.
    private $PAIR_CODE_LENGTH_ = 10;

    // First place value of the pairs (if the last pair value is 1).
    private $PAIR_FIRST_PLACE_VALUE_;

    // Inverse of the precision of the pair section of the code.
    private $PAIR_PRECISION_;

    // The resolution values in degrees for each position in the lat/lng pair
    // encoding. These give the place value of each position, and therefore the
    // dimensions of the resulting area.
    private $PAIR_RESOLUTIONS_ = [20.0, 1.0, .05, .0025, .000125];

    // Number of digits in the grid precision part of the code.
    private $GRID_CODE_LENGTH_;

    // Number of columns in the grid refinement method.
    private $GRID_COLUMNS_ = 4;

    // Number of rows in the grid refinement method.
    private $GRID_ROWS_ = 5;

    // First place value of the latitude grid (if the last place is 1).
    private $GRID_LAT_FIRST_PLACE_VALUE_;

    // First place value of the longitude grid (if the last place is 1).
    private $GRID_LNG_FIRST_PLACE_VALUE_;

    // Multiply latitude by this much to make it a multiple of the finest
    // precision.
    private $FINAL_LAT_PRECISION_;

    // Multiply longitude by this much to make it a multiple of the finest
    // precision.
    private $FINAL_LNG_PRECISION_;

    // Minimum length of a code that can be shortened.
    private $MIN_TRIMMABLE_CODE_LEN_ = 6;

    /**
    @return {string} Returns the OLC alphabet.
    */

    public function __construct()
    {
        $this->ENCODING_BASE_ = strlen($this->CODE_ALPHABET_);
        $this->PAIR_FIRST_PLACE_VALUE_ = pow(
            $this->ENCODING_BASE_, ($this->PAIR_CODE_LENGTH_ / 2 - 1));
        $this->PAIR_PRECISION_ = pow($this->ENCODING_BASE_, 3);
        $this->GRID_CODE_LENGTH_ = $this->MAX_DIGIT_COUNT_ - $this->PAIR_CODE_LENGTH_;
        $this->GRID_LAT_FIRST_PLACE_VALUE_ = pow(
            $this->GRID_ROWS_, ($this->GRID_CODE_LENGTH_ - 1));
        
        $this->GRID_LNG_FIRST_PLACE_VALUE_ = pow(
            $this->GRID_COLUMNS_, ($this->GRID_CODE_LENGTH_ - 1));
        
        $this->FINAL_LAT_PRECISION_ = $this->PAIR_PRECISION_ *
            pow($this->GRID_ROWS_, ($this->MAX_DIGIT_COUNT_ - $this->PAIR_CODE_LENGTH_));

        $this->FINAL_LNG_PRECISION_ = $this->PAIR_PRECISION_ *
            pow($this->GRID_COLUMNS_, ($this->MAX_DIGIT_COUNT_ - $this->PAIR_CODE_LENGTH_));
    }
    /*
    public function getAlphabet()
    {
        return $this->CODE_ALPHABET_;
    }
    */
    /**
   * Determines if a code is valid.
   *
   * To be valid, all characters must be from the Open Location Code character
   * set with at most one separator. The separator can be in any even-numbered
   * position up to the eighth digit.
   *
   * @param {string} code The string to check.
   * @return {boolean} True if the string is a valid code.
   */
    private function isValid($code)
    {
        if (!$code || gettype($code) !== 'string') {
            return false;
        }
        // The separator is required.
        if (strpos($code, $this->SEPARATOR_) == -1) {
            return false;
        }
        if (strpos($code, $this->SEPARATOR_) != strripos($code, $this->SEPARATOR_)) {
            return false;
        }
        // Is it the only character?
        if (strlen($code) == 1) {
            return false;
        }
        // Is it in an illegal position?
        if (strpos($code, $this->SEPARATOR_) > $this->SEPARATOR_POSITION_ ||
            strpos($code, $this->SEPARATOR_) % 2 == 1) {
            return false;
        }
        // We can have an even number of padding characters before the separator,
        // but then it must be the final character.
        if (strpos($code, $this->PADDING_CHARACTER_) > -1) {
            // Short codes cannot have padding
            if (strpos($code, $this->SEPARATOR_) < $this->SEPARATOR_POSITION_) {
                return false;
            }
            // Not allowed to start with them!
            if (strpos($code, $this->PADDING_CHARACTER_) == 0) {
                return false;
            }
            
            // There can only be one group and it must have even length.
            $pattern = "/" . $this->PADDING_CHARACTER_. "+/";
            preg_match_all($code, $pattern, $padMatch);
            if (strlen($padMatch) > 1 || strlen($padMatch[0]) % 2 == 1 ||
                strlen($padMatch[0]) > $this->SEPARATOR_POSITION_ - 2) {
                return false;
            }
            // If the code is long enough to end with a separator, make sure it does.
            if (stripos(strlen($code) - 1) != $this->SEPARATOR_) {
                return false;
            }
        }
        return true;
    }

    /**
     * Determines if a code is a valid short code.
     *
     * @param {string} code The string to check.
     * @return {boolean} True if the string can be produced by removing four or
     *     more characters from the start of a valid code.
     */
    private function isShort($code)
    {
        // Check it's valid.
        if (!$this->isValid($code)) {
            return false;
        }
        // If there are less characters than expected before the SEPARATOR.
        if (stripos($code, $this->SEPARATOR_) >= 0 &&
            stripos($code, $this->SEPARATOR_) < $this->SEPARATOR_POSITION_) {
            return true;
        }
        return false;
    }

    /**
     * Determines if a code is a valid full Open Location Code.
     *
     * @param {string} code The string to check.
     * @return {boolean} True if the code represents a valid latitude and
     *     longitude combination.
     */
    private function isFull($code)
    {
        if (!$this->isValid($code)) {
            return false;
        }
        // If it's short, it's not full.
        if ($this->isShort($code)) {
            return false;
        }

        // Work out what the first latitude character indicates for latitude.
        $firstLatValue = strtoupper(stripos($this->CODE_ALPHABET_, substr($code,0,1))) * $this->ENCODING_BASE_;
        if ($firstLatValue >= $this->LATITUDE_MAX_ * 2) {
            // The code would decode to a latitude of >= 90 degrees.
            return false;
        }
        if (strlen($code) > 1) {
            // Work out what the first longitude character indicates for longitude.
            $firstLngValue = strtoupper(stripos($this->CODE_ALPHABET_, substr($code,1,1))) * $this->ENCODING_BASE_;
            if ($firstLngValue >= $this->LONGITUDE_MAX_ * 2) {
                // The code would decode to a longitude of >= 180 degrees.
                return false;
            }
        }
        return true;
    }

    /**
   * Encode a location into an Open Location Code.
   *
   * @param {number} latitude The latitude in signed decimal degrees. It will
   *     be clipped to the range -90 to 90.
   * @param {number} longitude The longitude in signed decimal degrees. Will be
   *     normalised to the range -180 to 180.
   * @param {?number} codeLength The length of the code to generate. If
   *     omitted, the value OpenLocationCode.CODE_PRECISION_NORMAL will be used.
   *     For a more precise result, OpenLocationCode.CODE_PRECISION_EXTRA is
   *     recommended.
   * @return {string} The code.
   * @throws {Exception} if any of the input values are not numbers.
   */
    public function encode(float $latitude, float $longitude, $codeLength = null)
    {
        if (!isset($codeLength)) {
            $codeLength = $this->CODE_PRECISION_NORMAL;
            } else {
            $codeLength = min($this->MAX_DIGIT_COUNT_, (int)$codeLength);
        }
        //NaN Check Fn
        if (is_nan($latitude) || is_nan($longitude) || is_nan($codeLength)) {
            //throw new Error('ValueError: Parameters are not numbers');
        }

        if ($codeLength < 2 ||
            ($codeLength < $this->PAIR_CODE_LENGTH_ && $codeLength % 2 == 1)) {
            //throw new Error('IllegalArgumentException: Invalid Open Location Code length');
        }

        // Ensure that latitude and longitude are valid.
        $latitude = $this->clipLatitude($latitude);
        $longitude = $this->normalizeLongitude($longitude);
        // Latitude 90 needs to be adjusted to be just less, so the returned code
        // can also be decoded.
        if ($latitude == 90) {
            $latitude = $latitude - $this->computeLatitudePrecision($codeLength);
        }
        $code = '';

        // Compute the code.
        // This approach converts each value to an integer after multiplying it by
        // the final precision. This allows us to use only integer operations, so
        // avoiding any accumulation of floating point representation errors.

        // Multiply values by their precision and convert to positive.
        // Force to integers so the division operations will have integer results.
        // Note: PHP requires rounding before truncating to ensure precision!

        $latVal =
            floor(round(($latitude + $this->LATITUDE_MAX_) * $this->FINAL_LAT_PRECISION_ * 10 ** 6) / 10 ** 6);
        $lngVal =
            floor(round(($longitude + $this->LONGITUDE_MAX_) * $this->FINAL_LNG_PRECISION_ * 10 ** 6) / 10 ** 6);        

        // Compute the grid part of the code if necessary.
        if ($codeLength > $this->PAIR_CODE_LENGTH_) {
            for ($i = 0; $i < $this->MAX_DIGIT_COUNT_ - $this->PAIR_CODE_LENGTH_; $i++) {
                $latDigit = $latVal % $this->GRID_ROWS_;
                $lngDigit = $lngVal % $this->GRID_COLUMNS_;
                $ndx = $latDigit * $this->GRID_COLUMNS_ + $lngDigit;
            $code = substr($this->CODE_ALPHABET_, $ndx, 1) . $code;
            // Note! Integer division.
            $latVal = floor($latVal / $this->GRID_ROWS_);
            $lngVal = floor($lngVal / $this->GRID_COLUMNS_);
            }
        } else {
            $latVal = floor($latVal / pow($this->GRID_ROWS_, $this->GRID_CODE_LENGTH_));
            $lngVal = floor($lngVal / pow($this->GRID_COLUMNS_, $this->GRID_CODE_LENGTH_));
        }
        
        // Compute the pair section of the code.
        for ($i = 0; $i < $this->PAIR_CODE_LENGTH_ / 2; $i++) {
            $code = substr($this->CODE_ALPHABET_, ($lngVal % $this->ENCODING_BASE_), 1) . $code;
            $code = substr($this->CODE_ALPHABET_, ($latVal % $this->ENCODING_BASE_), 1) . $code;
            $latVal = floor($latVal / $this->ENCODING_BASE_);
            $lngVal = floor($lngVal / $this->ENCODING_BASE_);
        }
        
        // Add the separator character.
        $code = substr($code, 0, $this->SEPARATOR_POSITION_ - 1) . $this->SEPARATOR_ . substr($code,$this->SEPARATOR_POSITION_);

        // If we don't need to pad the code, return the requested section.
        if ($codeLength >= $this->SEPARATOR_POSITION_) {
            return substr($code, 0, $codeLength);
        }
        
        // Pad and return the code.
        return substr($code, 0, $codeLength - 1) .
            join($this->PADDING_CHARACTER_, $this->SEPARATOR_POSITION_ - $codeLength + 1) . $this->SEPARATOR_;        
    }

    /**
     * Decodes an Open Location Code into its location coordinates.
     *
     * Returns a CodeArea object that includes the coordinates of the bounding
     * box - the lower left, center and upper right.
     *
     * @param {string} code The code to decode.
     * @return {OpenLocationCode.CodeArea} An object with the coordinates of the
     *     area of the code.
     * @throws {Exception} If the code is not valid.
     */

    public function decode($code)
    {
        // This calculates the values for the pair and grid section separately, using
        // integer arithmetic. Only at the final step are they converted to floating
        // point and combined.
        
        if (!$this->isFull($code)) {
            // throw new Error('IllegalArgumentException: ' +
            //     'Passed Open Location Code is not a valid full code: ' + code);
        }
        // Strip the '+' and '0' characters from the code and convert to upper case.
        $code = strtoupper(str_ireplace(['+', '0'], ['', ''], $code));
        

        // Initialise the values for each section. We work them out as integers and
        // convert them to floats at the end.
        $normalLat = -$this->LATITUDE_MAX_ * $this->PAIR_PRECISION_;
        $normalLng = -$this->LONGITUDE_MAX_ * $this->PAIR_PRECISION_;
        $gridLat = 0;
        $gridLng = 0;
        // How many digits do we have to process?
        $digits = min(strlen($code), $this->PAIR_CODE_LENGTH_);
        // Define the place value for the most significant pair.
        $pv = $this->PAIR_FIRST_PLACE_VALUE_;
        // Decode the paired digits.
        for ($i = 0; $i < $digits; $i += 2) {            
            $normalLat += strpos($this->CODE_ALPHABET_, substr($code, $i , 1)) * $pv;
            $normalLng += strpos($this->CODE_ALPHABET_, substr($code, $i + 1 , 1)) * $pv;
            if ($i < $digits - 2) {
                $pv /= $this->ENCODING_BASE_;
            }
        }

        // Convert the place value to a float in degrees.
        $latPrecision = $pv / $this->PAIR_PRECISION_;
        $lngPrecision = $pv / $this->PAIR_PRECISION_;
        // Process any extra precision digits.
        if (strlen($code) > $this->PAIR_CODE_LENGTH_) {
        // Initialise the place values for the grid.
        $rowpv = $this->GRID_LAT_FIRST_PLACE_VALUE_;
        $colpv = $this->GRID_LNG_FIRST_PLACE_VALUE_;
        // How many digits do we have to process?
        $digits = min(strlen($code), $this->MAX_DIGIT_COUNT_);
        for ($i = $this->PAIR_CODE_LENGTH_; $i < $digits; $i++) {
            $digitVal = strpos($this->CODE_ALPHABET_, substr($code, $i , 1));
            $row = floor($digitVal / $this->GRID_COLUMNS_);
            $col = $digitVal % $this->GRID_COLUMNS_;
            $gridLat += $row * $rowpv;
            $gridLng += $col * $colpv;
            if ($i < $digits - 1) {
            $rowpv /= $this->GRID_ROWS_;
            $colpv /= $this->GRID_COLUMNS_;
            }
        }
        // Adjust the precisions from the integer values to degrees.
        $latPrecision = $rowpv / $this->FINAL_LAT_PRECISION_;
        $lngPrecision = $colpv / $this->FINAL_LNG_PRECISION_;
        }
        // Merge the values from the normal and extra precision parts of the code.
        $lat = $normalLat / $this->PAIR_PRECISION_ + $gridLat / $this->FINAL_LAT_PRECISION_;
        $lng = $normalLng / $this->PAIR_PRECISION_ + $gridLng / $this->FINAL_LNG_PRECISION_;
        // Multiple values by 1e14, round and then divide. This reduces errors due
        // to floating point precision.
        return $this->codeArea(
            round($lat * 10 ** 14) / 10 ** 14, 
            round($lng * 10 ** 14) / 10 ** 14,
            round(($lat + $latPrecision) * 10 ** 14) / 10**14,
            round(($lng + $lngPrecision) * 10 ** 14) / 10**14,
            min(strlen($code), $this->MAX_DIGIT_COUNT_)
        );

    }

    /**
   * Recover the nearest matching code to a specified location.
   *
   * Given a valid short Open Location Code this recovers the nearest matching
   * full code to the specified location.
   *
   * @param {string} shortCode A valid short code.
   * @param {number} referenceLatitude The latitude to use for the reference
   *     location.
   * @param {number} referenceLongitude The longitude to use for the reference
   *     location.
   * @return {string} The nearest matching full code to the reference location.
   * @throws {Exception} if the short code is not valid, or the reference
   *     position values are not numbers.
   */
    private function recoverNearest(
        $shortCode, $referenceLatitude, $referenceLongitude) {
    if (!isShort($shortCode)) {
        if (isFull($shortCode)) {
            return strtoupper($shortCode);
        } else {
        // throw new Error(
        //     'ValueError: Passed short code is not valid: ' + $shortCode);
        }
    }
    $referenceLatitude = (int)$referenceLatitude;
    $referenceLongitude = (int)$referenceLongitude;
    if (is_nan($referenceLatitude) || is_nan($referenceLongitude)) {
        // throw new Error('ValueError: Reference position are not numbers');
    }
    // Ensure that latitude and longitude are valid.
    $referenceLatitude = $this->clipLatitude($referenceLatitude);
    $referenceLongitude = $this->normalizeLongitude($referenceLongitude);

    // Clean up the passed code.
    $shortCode = strtoupper($shortCode);
    // Compute the number of digits we need to recover.
    $paddingLength = $this->SEPARATOR_POSITION_ - stripos($shortCode, $this->SEPARATOR_);
    // The resolution (height and width) of the padded area in degrees.
    $resolution = pow(20, 2 - ($paddingLength / 2));
    // Distance from the center to an edge (in degrees).
    $halfResolution = $resolution / 2.0;

    // Use the reference location to pad the supplied short code and decode it.
    $codeArea = $this->decode(
        substr($this->encode($referenceLatitude, $referenceLongitude), 0, $paddingLength)
        . $shortCode);
    // How many degrees latitude is the code from the reference? If it is more
    // than half the resolution, we need to move it north or south but keep it
    // within -90 to 90 degrees.
    
    if ($referenceLatitude + $halfResolution < $this->latitudeCenter &&
        $this->latitudeCenter - $resolution >= -$this->LATITUDE_MAX_) {
        // If the proposed code is more than half a cell north of the reference location,
        // it's too far, and the best match will be one cell south.
        $this->latitudeCenter -= $resolution;
    } elseif ($referenceLatitude - $halfResolution > $this->latitudeCenter &&
            $this->latitudeCenter + $resolution <= $this->LATITUDE_MAX_) {
        // If the proposed code is more than half a cell south of the reference location,
        // it's too far, and the best match will be one cell north.
        $this->latitudeCenter += $resolution;
    }

    // How many degrees longitude is the code from the reference?
    if ($referenceLongitude + $halfResolution < $this->longitudeCenter) {
        $this->longitudeCenter -= $resolution;
    } elseif ($referenceLongitude - $halfResolution > $this->longitudeCenter) {
        $this->longitudeCenter += $resolution;
    }

    return $this->encode(
        $this->latitudeCenter, $this->longitudeCenter, $this->codeLength);
    }

    /**
   * Remove characters from the start of an OLC code.
   *
   * This uses a reference location to determine how many initial characters
   * can be removed from the OLC code. The number of characters that can be
   * removed depends on the distance between the code center and the reference
   * location.
   *
   * @param {string} code The full code to shorten.
   * @param {number} latitude The latitude to use for the reference location.
   * @param {number} longitude The longitude to use for the reference location.
   * @return {string} The code, shortened as much as possible that it is still
   *     the closest matching code to the reference location.
   * @throws {Exception} if the passed code is not a valid full code or the
   *     reference location values are not numbers.
   */
    private function shorten($code, float $latitude, float $longitude) 
    {
        if (!$this->isFull($code)) {
            // throw new Error('ValueError: Passed code is not valid and full: ' + code);
        }
        if (stripos($code, $this->PADDING_CHARACTER_) != -1) {
            // throw new Error('ValueError: Cannot shorten padded codes: ' + code);
        }
        $code = strtoupper($code);
        $codeArea = $this->decode($code);
        if ($this->codeLength < $this->MIN_TRIMMABLE_CODE_LEN_) {
            // throw new Error(
            //     'ValueError: Code length must be at least ' . $this->MIN_TRIMMABLE_CODE_LEN_);
        }
        // Ensure that latitude and longitude are valid.
        if (is_nan($latitude) || is_nan($longitude)) {
            // throw new Error('ValueError: Reference position are not numbers');
        }
        $latitude = $this->clipLatitude($latitude);
        $longitude = $this->normalizeLongitude($longitude);
        // How close are the latitude and longitude to the code center.
        $range = max(
            abs($this->latitudeCenter - $latitude),
            abs($this->longitudeCenter - $longitude));
        for ($i = strlen($this->PAIR_RESOLUTIONS_) - 2; i >= 1; $i--) {
            // Check if we're close enough to shorten. The range must be less than 1/2
            // the resolution to shorten at all, and we want to allow some safety, so
            // use 0.3 instead of 0.5 as a multiplier.
            if ($range < ($this->PAIR_RESOLUTIONS_[$i] * 0.3)) {
            // Trim it.
            return substr($code, ($i + 1) * 2 - 1);
            }
        }
        return $code;
    }

    /**
     * Clip a latitude into the range -90 to 90.
     *
     * @param {number} latitude
     * @return {number} The latitude value clipped to be in the range.
     */
    private function clipLatitude($latitude)
    {
        return min(90, max(-90, $latitude));
    }

    /**
     * Compute the latitude precision value for a given code length.
     * Lengths <= 10 have the same precision for latitude and longitude, but
     * lengths > 10 have different precisions due to the grid method having
     * fewer columns than rows.
     * @param {number} codeLength
     * @return {number} The latitude precision in degrees.
     */
    private function computeLatitudePrecision($codeLength)
    {
        if ($codeLength <= 10) {
            return pow($this->ENCODING_BASE_, floor($codeLength / -2 + 2));
        }
        return pow($this->ENCODING_BASE_, -3) / pow($this->GRID_ROWS_, $codeLength - 10);
    }

    /**
     * Normalize a longitude into the range -180 to 180, not including 180.
     *
     * @param {number} longitude
     * @return {number} Normalized into the range -180 to 180.
     */
    private function normalizeLongitude($longitude) 
    {
        while ($longitude < -180) {
            $longitude = $longitude + 360;
        }
        while ($longitude >= 180) {
            $longitude = $longitude - 360;
        }
        return $longitude;
    }

    /**
     * Coordinates of a decoded Open Location Code.
     *
     * The coordinates include the latitude and longitude of the lower left and
     * upper right corners and the center of the bounding box for the area the
     * code represents.
     * @param {number} latitudeLo
     * @param {number} longitudeLo
     * @param {number} latitudeHi
     * @param {number} longitudeHi
     * @param {number} codeLength
     *
     * @return OpenLocationCode Object
     */
    private function codeArea($latitudeLo, $longitudeLo, $latitudeHi, $longitudeHi, $codeLength)
    {
        /**
         * The latitude of the SW corner.
         * @type {number}
         */
        $this->latitudeLo = $latitudeLo;
        /**
         * The longitude of the SW corner in degrees.
         * @type {number}
         */
        $this->longitudeLo = $longitudeLo;
        /**
         * The latitude of the NE corner in degrees.
         * @type {number}
         */
        $this->latitudeHi = $latitudeHi;
        /**
         * The longitude of the NE corner in degrees.
         * @type {number}
         */
        $this->longitudeHi = $longitudeHi;
        /**
         * The number of digits in the code.
         * @type {number}
         */
        $this->codeLength = $codeLength;
        /**
         * The latitude of the center in degrees.
         * @type {number}
         */
        $this->latitudeCenter = min(
            $latitudeLo + ($latitudeHi - $latitudeLo) / 2, $this->LATITUDE_MAX_);
        /**
         * The longitude of the center in degrees.
         * @type {number}
         */
        $this->longitudeCenter = min(
            $longitudeLo + ($longitudeHi - $longitudeLo) / 2, $this->LONGITUDE_MAX_);

        return $this;
    }
}
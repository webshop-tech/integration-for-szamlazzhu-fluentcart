<?php

namespace SzamlazzHuFluentCart;

if (!defined('ABSPATH')) {
    exit;
}

function build_taxpayer_xml($api_key, $vat_id_base) {
    $xml = new \SimpleXMLElement('<?xml version="1.0" encoding="UTF-8"?><xmltaxpayer xmlns="http://www.szamlazz.hu/xmltaxpayer" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xsi:schemaLocation="http://www.szamlazz.hu/xmltaxpayer http://www.szamlazz.hu/docs/xsds/agent/xmltaxpayer.xsd"></xmltaxpayer>');
    
    $beallitasok = $xml->addChild('beallitasok');
    $beallitasok->addChild('szamlaagentkulcs', $api_key);
    
    $xml->addChild('torzsszam', substr($vat_id_base, 0, 8));
    
    return $xml->asXML();
}

function remove_leading_letters($vat_id) {
    if (\strlen($vat_id) >= 2 && \ctype_alpha(\substr($vat_id, 0, 2))) {
        return \substr($vat_id, 2);
    }
    return $vat_id;
}

function get_vat_data($key) {
	if (wp_using_ext_object_cache()) {
		return wp_cache_get($key, 'szamlazzhu');
	}
	return get_transient('szamlazzhu_' . $key);
}

function set_vat_data($key, $value): bool {
	if (wp_using_ext_object_cache()) {
		return wp_cache_set($key, $value, 'szamlazzhu', HOUR_IN_SECONDS);
	}
	return set_transient('szamlazzhu_' . $key, $value, HOUR_IN_SECONDS);
}

function get_taxpayer_api($order_id, $api_key, $vat_id) {
	$cache_key = sanitize_key($vat_id);
	$cached_result = get_vat_data($cache_key);

	if (false !== $cached_result) {
		if ($order_id > 0) {
			debug_log( $order_id, 'Taxpayer data found in cache', 'VAT number', $vat_id );
		}
		return $cached_result;
	}
    $vatParts = explode('-', remove_leading_letters($vat_id));
    $vat_id_base = $vatParts[0];
    
    $xml_string = build_taxpayer_xml($api_key, $vat_id_base);
    
    $multipart = build_multipart_body($xml_string, 'action-szamla_agent_taxpayer');
    
    $response = \wp_remote_post('https://www.szamlazz.hu/szamla/', array(
        'timeout' => 30,
        'headers' => array(
            'Content-Type' => 'multipart/form-data; boundary=' . $multipart['boundary'],
        ),
        'body' => $multipart['body'],
    ));
    
    if (\is_wp_error($response)) {
        return $response;
    }
    
    $response_code = \wp_remote_retrieve_response_code($response);
    $response_body = \wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
        return create_error($order_id, 'api_error', 'Taxpayer API returned error code', $response_code);
    }
    
    try {
        $xml = new \SimpleXMLElement($response_body);
        
        $xml->registerXPathNamespace('ns2', 'http://schemas.nav.gov.hu/OSA/3.0/api');
        $xml->registerXPathNamespace('ns3', 'http://schemas.nav.gov.hu/OSA/3.0/base');
        
        $taxpayerValidity = $xml->xpath('//ns2:taxpayerValidity');
        if (empty($taxpayerValidity) || "true" !== (string)$taxpayerValidity[0]) {
            return create_error($order_id, 'invalid_taxpayer', 'Taxpayer is not valid');
        }
        
        $data = array(
            'valid' => true,
        );
        
        $taxpayer_short_name = $xml->xpath('//ns2:taxpayerShortName');
        $taxpayer_name = $xml->xpath('//ns2:taxpayerName');
        
        if (!empty($taxpayer_short_name)) {
            $data['name'] = (string)$taxpayer_short_name[0];
        } elseif (!empty($taxpayer_name)) {
            $data['name'] = (string)$taxpayer_name[0];
        }
        
        $taxpayer_id = $xml->xpath('//ns3:taxpayerId');
        $vat_code = $xml->xpath('//ns3:vatCode');
        $county_code = $xml->xpath('//ns3:countyCode');
        
        if (!empty($taxpayer_id) && !empty($vat_code) && !empty($county_code)) {
            $data['vat_id'] = sprintf(
                '%s-%s-%s',
                (string)$taxpayer_id[0],
                (string)$vat_code[0],
                (string)$county_code[0]
            );
        }
        
        $postal_code = $xml->xpath('//ns3:postalCode');
        $city = $xml->xpath('//ns3:city');
        $street_name = $xml->xpath('//ns3:streetName');
        $public_place = $xml->xpath('//ns3:publicPlaceCategory');
        $number = $xml->xpath('//ns3:number');
        $door = $xml->xpath('//ns3:door');
        
        if (!empty($postal_code)) {
            $data['postcode'] = (string)$postal_code[0];
        }
        
        if (!empty($city)) {
            $data['city'] = (string)$city[0];
        }
        
        if (!empty($street_name)) {
            $address_parts = [(string)$street_name[0]];
            
            if (!empty($public_place)) {
                $address_parts[] = (string)$public_place[0];
            }
            
            if (!empty($number)) {
                $address_parts[] = (string)$number[0];
            }
            
            if (!empty($door)) {
                $address_parts[] = (string)$door[0];
            }
            
            $data['address'] = implode(' ', $address_parts);
        }

	    set_vat_data($cache_key, $data);

        return $data;
        
    } catch (\Exception $e) {
        return create_error($order_id, 'parse_error', 'Failed to parse taxpayer XML', $e->getMessage());
    }
}
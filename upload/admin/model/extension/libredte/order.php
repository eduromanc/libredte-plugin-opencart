<?php

/**
 * LibreDTE
 * Copyright (C) SASCO SpA (https://sasco.cl)
 *
 * Este programa es software libre: usted puede redistribuirlo y/o
 * modificarlo bajo los términos de la Licencia Pública General Affero de GNU
 * publicada por la Fundación para el Software Libre, ya sea la versión
 * 3 de la Licencia, o (a su elección) cualquier versión posterior de la
 * misma.
 *
 * Este programa se distribuye con la esperanza de que sea útil, pero
 * SIN GARANTÍA ALGUNA; ni siquiera la garantía implícita
 * MERCANTIL o de APTITUD PARA UN PROPÓSITO DETERMINADO.
 * Consulte los detalles de la Licencia Pública General Affero de GNU para
 * obtener una información más detallada.
 *
 * Debería haber recibido una copia de la Licencia Pública General Affero de GNU
 * junto a este programa.
 * En caso contrario, consulte <http://www.gnu.org/licenses/agpl.html>.
 */

/**
 * Modelo para trabajar con las órdenes de compra de OpenCart
 * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
 * @version 2016-01-30
 */
class ModelExtensionLibredteOrder extends Model
{

    /**
     * Constructor del controlador
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2016-01-26
     */
	 
    public function __construct($registry)
    {
        $this->registry = $registry;
        $this->registry->set('libredte', new Libredte($this->registry));
    }
	
	
	
    /**
     * Método que crea la factura en LibreDTE
     * @param order_id ID de la orden que se quiere generar su factura
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2016-01-30
     */
    public function createInvoiceNo($order_id)
    {
	//	$this->libredte = new libredte();
      
        $dte = $this->getDte($order_id);
        if (!$dte){
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"No se pudo Obtener DTE: \n");
        //fputs($fp, $order_id . " \n");
        fclose($fp);
          
            return false;
        }
        $order_info = $this->model_sale_order->getOrder($order_id);
        $libredte_info = $this->model_setting_setting->getSetting(
            'module_libredte', $order_info['store_id']
        );
        // emitir dte temporal
        $response = $this->libredte->post(
            $libredte_info['module_libredte_url'].'/api/dte/documentos/emitir',
            $libredte_info['module_libredte_preauth_hash'],
            $dte
        );
        if ($response['status']['code']!=200) {
            $this->log->write($response['body']);
            return false;
        }

        $dte_tmp = $response['body'];
        // generar dte definitivo y enviar al sii
        $response = $this->libredte->post(
            $libredte_info['module_libredte_url'].'/api/dte/documentos/generar',
            $libredte_info['module_libredte_preauth_hash'],
            $dte_tmp
        );
        if ($response['status']['code']!=200) {
            $this->log->write($response['body']);
            return false;
        }

        $invoice_prefix = 'T'.$response['body']['dte'].'F';
        
        
        $invoice_no = $response['body']['folio'];
        $this->db->query("UPDATE `" . DB_PREFIX . "order` SET invoice_no = '" . (int)$invoice_no . "', invoice_prefix = '" . $this->db->escape($invoice_prefix) . "' WHERE order_id = '" . (int)$order_id . "'");

		/*      
      	ob_start();
		var_dump($response);
		$output = ob_get_clean();
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"Respuesta de emision de DTE: \n");
        fputs($fp, $output . " \n");
        fclose($fp);
      	*/
      	
        $linkpdf = $libredte_info['module_libredte_url'] . '/dte/dte_emitidos/pdf/' . $response['body']['dte'] . '/' . $invoice_no . '/1/' . $libredte_info['module_libredte_contribuyente'] . '/' . $response['body']['fecha'] . '/' . $response['body']['total'];
      	$linkxml = $libredte_info['module_libredte_url'] . '/dte/dte_emitidos/xml/' . $response['body']['dte'] . '/' . $invoice_no . '/' . $libredte_info['module_libredte_contribuyente'] . '/' . $response['body']['fecha'] . '/' . $response['body']['total'];
      	$this->db->query("UPDATE `" . DB_PREFIX . "libredte` SET linkpdf = '" . $linkpdf . "' , linkxml = '" . $linkxml . "' WHERE order_id = '" . (int)$order_id . "'");
      
      return $invoice_prefix.$invoice_no;
    }

    /**
     * Método que crea el arreglo con los datos del DTE según especificación de
     * LibreDTE (mismo esquema que el SII)
     * @param order_id ID de la orden que se quiere obtener sus datos
     * @author Esteban De La Fuente Rubio, DeLaF (esteban[at]sasco.cl)
     * @version 2016-01-30
     */
    public function getDte($order_id, $TipoDTE = 33)
    {
        $this->load->model('sale/order');
        $order_info = $this->model_sale_order->getOrder($order_id);
        if (!$order_info){
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"Que Raro, no se pudo obtener la informacion de la orden: \n la Factura tiene numero: " . $order_info['invoice_no']);
        //fputs($fp, $order_id . " \n");
        fclose($fp);  
            return false;
        }
      
      
      $coupon = 0;
      $shipping = 0;
      // Se obtiene el valor del despacho, si es que existe
		$order_totals = $this->model_sale_order->getOrderTotals($order_id);
       foreach ($order_totals as $total){
       if ($total['code'] == 'shipping'){
       $shipping = $total['value'];
       }
       if ($total['code'] == 'coupon'){
       $coupon = $total['value'];
       }  
        
       }
       
      
      
        $this->load->model('setting/setting');
        $libredte_info = $this->model_setting_setting->getSetting(
            'module_libredte', $order_info['store_id']
        );

	   $result = $this->db->query("SELECT * FROM `".DB_PREFIX."libredte` WHERE order_id=" . $order_id);
	   if ($result->num_rows){
	   $rut = $result->row['rut'];
	   $giro = $result->row['giro'];
       $rsocial = $result->row['rsocial'];
       $obs = $result->row['obs'];
       $oc = $result->row['oc'];
       $fecha_oc = $result->row['fecha_oc'];
       if (empty($fecha_oc)){
       $fecha_oc = date('Y-m-d');
       }  
         
         
         
       $boletaofactura = $result->row['boletaofactura'];
	    
	   if ( abs($coupon) > 0){
	   if (($boletaofactura == 'factura')){
       $coupon = abs($coupon);
       } 
       else
       {
       $coupon = round(abs($coupon) * 1.19);
       } 
	   }

		if ($shipping > 0){
	   if ($boletaofactura == 'factura'){
       $shipping = round($shipping);
       } 
       else
       {
       $shipping = round($shipping * 1.19);
       } 
		}  
         
       if ($boletaofactura == 'boleta'){
       $TipoDTE = 39;
       } 
       else
       {
       $TipoDTE = 33;
       } 
     
       if ($boletaofactura == 'boleta'){
       if (empty($rut) || empty($rsocial)){
       $rut = '66666666-6';
       }
       if (empty($giro)){
       $giro = 'Sin giro informado';
       }
       if (empty($rsocial)){
       $rsocial = 'Sin razón social informada';
       }
        
       }  
         
         
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"Se obtuvo el siguiente RUT: \n" . $rut . " y el siguiente giro: " . $giro);
        fclose($fp);
         
         
	   }
	   
           
           
        $product_code = $libredte_info['module_libredte_producto_codigo'];
        if (empty($rut) or empty($giro) or empty($rsocial)){
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"El rut, el giro o la razón social están vacíos" . " \n");
        fclose($fp);   
            return false;
        }
        
      
      
      
        /*
      	if (!$this->libredte->checkRut(trim($rut))){
        $fp = fopen("libredte.log", "a+");
		fputs($fp,"El Rut esta erroneo" . " \n");
        fclose($fp);   
            return false;
        }
      	*/
      
        // crear arreglo con detalles de productos y/o servicios
        $this->load->model('extension/libredte/product');
        $products = $this->model_sale_order->getOrderProducts($order_id);
        $Detalle = [];
        foreach ($products as $product) {
            $product_info = $this->model_extension_libredte_product->getProduct($product['product_id']);
            $price = $product_info['price'];
            $discount = $product_info['price'] - $product_info['special'];
            if ($product['price']!=($price-$discount)) {
                $price = $product['price'];
                $discount = 0;
            }
          
          
          // En el caso de la factura el precio llega tal cual al sistema de LibreDTE
          // pero en el caso de la boleta le agregamos el IVA
          
          if ($boletaofactura == 'boleta'){
          $price = round($price * 1.19);
          }
          
     
            $Detalle[] = [
                'CdgItem' => $product_info[$product_code] ? [
                    'TpoCodigo' => 'INT1',
                    'VlrCodigo' => substr($product_info[$product_code], 0, 35),
                ] : false,
                'IndExe' => $product_info['tax_class_id'] ? false : 1,
                'NmbItem' => substr($product['name'], 0, 80),
                'DscItem' => '',
                'QtyItem' => $product['quantity'],
                'UnmdItem' => 'ud.',
                'PrcItem' => round($price),
                'DescuentoMonto' => $discount ? round($discount) : false
            ];
        }
      
      // Se agrega el despacho en caso que exista *********************************
      
      if ($shipping > 0){
            $Detalle[] = [
                'CdgItem' => [
                    'TpoCodigo' => 'INT1',
                    'VlrCodigo' => '00000001',
                ],
                'IndExe' => false,
                'NmbItem' => 'Costo de Envío',
                'DscItem' => '',
                'QtyItem' => 1,
                'UnmdItem' => '',
                'PrcItem' => $shipping,
                'DescuentoMonto' => false
            ];
      
      }
      
      //*************************************************
      //***************** Se envia descuento global, en caso que exista
          if ($coupon){
            $dctoglobal[] = [
                'NroLinDR' => 1,
                'TpoMov' => 'D',
                'TpoValor' => '$',
                'ValorDR' => $coupon
            ];
      }
            
      
      
      //************************************************
      
        if (empty($Detalle)){
         $fp = fopen("libredte.log", "a+");
		fputs($fp,"El listado de productos esta vacio" . " \n");
        fclose($fp);    
            return false;
        }
            
      
      
if ($coupon){      
      
      if (!empty($oc)){

      
      $respuesta = [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => $TipoDTE,
                    'Folio' => 0,
                    'FchEmis' => date('Y-m-d'),
                    'TermPagoGlosa' => $obs,
                ],
                'Emisor' => [
                    'RUTEmisor' => $libredte_info['module_libredte_contribuyente'].'-'.$this->libredte->dv($libredte_info['module_libredte_contribuyente']),
                ],
                'Receptor' => [
                    'RUTRecep' => $rut,
                     //'RznSocRecep' => substr($order_info['customer'], 0, 100),
                  	'RznSocRecep' => substr($rsocial, 0, 40),
                    'GiroRecep' => substr($giro, 0, 40),
                    'Contacto' => substr($order_info['telephone'], 0, 80),
                    'CorreoRecep' => substr($order_info['email'], 0, 80),
                    'DirRecep' => substr($order_info['payment_address_1'].(!empty($order_info['payment_address_2'])?(', '.$order_info['payment_address_2']):''), 0, 70),
                    'CmnaRecep' => substr($order_info['payment_city'], 0, 20),
                ],
            ],
            'Detalle' => $Detalle,
            'DscRcgGlobal' => $dctoglobal,
        	'Referencia' => [
			'NroLinRef' => 1,
			'TpoDocRef' => 801,
			'FolioRef' => $oc,
			'FchRef' => $fecha_oc,
			],
      		    ];

      }
      else
      {
      
        $respuesta = [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => $TipoDTE,
                    'Folio' => 0,
                    'FchEmis' => date('Y-m-d'),
                    'TermPagoGlosa' => $obs,
                ],
                'Emisor' => [
                    'RUTEmisor' => $libredte_info['module_libredte_contribuyente'].'-'.$this->libredte->dv($libredte_info['module_libredte_contribuyente']),
                ],
                'Receptor' => [
                    'RUTRecep' => $rut,
                     //'RznSocRecep' => substr($order_info['customer'], 0, 100),
                  	'RznSocRecep' => substr($rsocial, 0, 50),
                    'GiroRecep' => substr($giro, 0, 40),
                    'Contacto' => substr($order_info['telephone'], 0, 80),
                    'CorreoRecep' => substr($order_info['email'], 0, 80),
                    'DirRecep' => substr($order_info['payment_address_1'].(!empty($order_info['payment_address_2'])?(', '.$order_info['payment_address_2']):''), 0, 70),
                    'CmnaRecep' => substr($order_info['payment_city'], 0, 20),
                ],
            ],
            'Detalle' => $Detalle,
            'DscRcgGlobal' => $dctoglobal,
          	
      		    ];    
        
      }
      
}      
else
{
//En caso que no haya cupon de descuento global

      if (!empty($oc)){
      
      $respuesta = [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => $TipoDTE,
                    'Folio' => 0,
                    'FchEmis' => date('Y-m-d'),
                    'TermPagoGlosa' => $obs,
                ],
                'Emisor' => [
                    'RUTEmisor' => $libredte_info['module_libredte_contribuyente'].'-'.$this->libredte->dv($libredte_info['module_libredte_contribuyente']),
                ],
                'Receptor' => [
                    'RUTRecep' => $rut,
                     //'RznSocRecep' => substr($order_info['customer'], 0, 100),
                  	'RznSocRecep' => substr($rsocial, 0, 40),
                    'GiroRecep' => substr($giro, 0, 40),
                    'Contacto' => substr($order_info['telephone'], 0, 80),
                    'CorreoRecep' => substr($order_info['email'], 0, 80),
                    'DirRecep' => substr($order_info['payment_address_1'].(!empty($order_info['payment_address_2'])?(', '.$order_info['payment_address_2']):''), 0, 70),
                    'CmnaRecep' => substr($order_info['payment_city'], 0, 20),
                ],
            ],
            'Detalle' => $Detalle,
        	'Referencia' => [
			'NroLinRef' => 1,
			'TpoDocRef' => 801,
			'FolioRef' => $oc,
			'FchRef' => $fecha_oc,
			],
      		    ];

      }
      else
      {
      
        $respuesta = [
            'Encabezado' => [
                'IdDoc' => [
                    'TipoDTE' => $TipoDTE,
                    'Folio' => 0,
                    'FchEmis' => date('Y-m-d'),
                    'TermPagoGlosa' => $obs,
                ],
                'Emisor' => [
                    'RUTEmisor' => $libredte_info['module_libredte_contribuyente'].'-'.$this->libredte->dv($libredte_info['module_libredte_contribuyente']),
                ],
                'Receptor' => [
                    'RUTRecep' => $rut,
                     //'RznSocRecep' => substr($order_info['customer'], 0, 100),
                  	'RznSocRecep' => substr($rsocial, 0, 50),
                    'GiroRecep' => substr($giro, 0, 40),
                    'Contacto' => substr($order_info['telephone'], 0, 80),
                    'CorreoRecep' => substr($order_info['email'], 0, 80),
                    'DirRecep' => substr($order_info['payment_address_1'].(!empty($order_info['payment_address_2'])?(', '.$order_info['payment_address_2']):''), 0, 70),
                    'CmnaRecep' => substr($order_info['payment_city'], 0, 20),
                ],
            ],
            'Detalle' => $Detalle,
          	
      		    ];    
        
      }
      

}
      
   
        // entregar arreglo con datos del DTE
        return $respuesta;
          
    
    }

}

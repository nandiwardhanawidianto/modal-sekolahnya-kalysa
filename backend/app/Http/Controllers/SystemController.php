<?php
namespace App\Http\Controllers;
class SystemController extends Controller
{
 public function health(){return response()->json(['app'=>config('app.name'),'php'=>PHP_VERSION,'timezone'=>config('app.timezone'),'extensions'=>['zip'=>extension_loaded('zip'),'xml'=>extension_loaded('xml'),'xmlreader'=>class_exists(\XMLReader::class),'simplexml'=>function_exists('simplexml_load_string'),'dom'=>class_exists(\DOMDocument::class),'mbstring'=>extension_loaded('mbstring'),'pdo_mysql'=>extension_loaded('pdo_mysql'),'fileinfo'=>extension_loaded('fileinfo')]]);}
}

<?php # vim: syntax=php tabstop=2 softtabstop=2 shiftwidth=2 expandtab textwidth=80 autoindent
# Copyright (C) 2010  Jean-Jacques Puig
#
# This program is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 3 of the License, or
# (at your option) any later version.
#
# This program is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with this program.  If not, see <http://www.gnu.org/licenses/>.

################################################################################
#
# Array functions
#
################################################################################

function array_diff_key_value($keys, $values) {
  $values_as_keys = array();

  foreach($values as $value)
    $values_as_keys[$value] = null;

  return array_diff_key($keys, $values_as_keys);
}

function array_key_value_if_notEmpty($key, $array, $default_value = null) {
  if ($array == null)
    return $default_value;

  if (array_key_exists($key, $array)
      && ($array[$key] != ''))
    return $array[$key];
  else
    return $default_value;
}

################################################################################
#
# Stored SQL Procedures functions
#   (Now useless; declarations will be removed in the future)
#
################################################################################

# spip_register_procedures($spip)
# This function is declared for compatibility.
# Will be removed in near future
function spip_register_procedures($spip) {}

# spip_unregister_procedures($spip)
# This function is declared for compatibility.
# Will be removed in near future
function spip_unregister_procedures($spip) {}

################################################################################
#
# Database related functions
#
################################################################################

# function spip_add_document
# $url:        document' source url
# $width:      document's width
# $height:     document's height
# $mode:       document's mode; often 'document' or 'image' (eg. for a thumbnail)
# $titre:      document's title
# $descriptif: document's description
# $path:       document's destination path on local server; often should be _IMG_DIR
function spip_add_document($url, $width, $height, $mode, $titre, $descriptif, $path) {
  $pathinfo = pathinfo(parse_url($url, PHP_URL_PATH));

  if(preg_match('/^data:/', $url)) {return;}
  if(preg_match('/^mailbox:/', $url)) {return;}

  $f_name = iconv('UTF-8', 'ISO-8859-1', preg_replace(
    '/\s+/',
    '_',
    urldecode($pathinfo['filename'])
  ));

  $url_hdl = @fopen($url, 'r', false);

  if (!($url_hdl || _HTML2SPIP_PRESERVE_DISTANT))
    die("$url is unreachable: cannot download !");

  $meta = @stream_get_meta_data($url_hdl);

  $f_ext = null;
  $f_size = null;

  if ($meta) {
    foreach ($meta['wrapper_data'] as $meta_value) {
      if (preg_match('/^Content-Type:/i', $meta_value)) {
        $f_ext = trim(preg_replace('|^.*/([^/; ]*)(.*)?$|', '$1', $meta_value));
        if (!strlen($f_ext))
          $f_ext = trim(preg_replace('/^Content-Type:/i', '', $meta_value));
      } elseif (preg_match('/^Content-Length:/i', $meta_value)) {
          $f_size = trim(preg_replace('/^Content-Length:/i', '', $meta_value));
      } elseif (strlen($f_ext) && strlen($f_size)) {
        break;
      }
    }
  }

  if ($f_ext == 'jpeg')
    $f_ext = 'jpg';

  if (!strlen($f_ext))
    $f_ext = $pathinfo['extension'];

  if (!strlen($f_ext))
    die("Could not determine content type for ($url)");

  $f_name .= ".$f_ext";

  if (!strlen($titre))
    $titre = $f_name;

  if (_HTML2SPIP_PRESERVE_DISTANT) {
    if ($url_hdl) {
      fclose($url_hdl);
      if (!strlen($f_size))
        $f_size = @filesize($url); # Worth trying: we could open file, but not
                                   # get metadata
    }
    if (!strlen($f_size))
      $f_size = 0;
    $fichier = $url;
  } else {
    @mkdir($path . '/' . $f_ext);
    $f_abs_name = $path . '/' . $f_ext . '/' . $f_name;
    if (!($f_hdl = fopen($f_abs_name, 'w')))
      die("Cannot open file for writing ($f_abs_name)");

    $f_size = 0;
    while (!feof($url_hdl))
      if (!($f_size += fwrite($f_hdl, fread($url_hdl, 8192))))
        die("Failed copy from ($url) to ($f_abs_name)");

    if (!(fclose($f_hdl)))
      die("Failed fclose on file ($f_abs_name)");

    fclose($url_hdl);

    $fichier = $f_ext . '/' . $f_name ;
  }

  if (!($result = sql_select(
                    'MAX(id_document)',
                    'spip_documents',
                    'fichier = ' . sql_quote($fichier)
                    )))
    die("Failed SQL query: ". sql_error());

  if (($row = sql_fetch($result)) && ((int) $row['MAX(id_document)']) > 0)
    return $row['MAX(id_document)'];

  $date = strftime('%F %T');

  $doc = array(
    'id_vignette' =>  0,
    'extension'   =>  $f_ext,
    'titre'       =>  $titre,
    'date'        =>  $date,
    'descriptif'  =>  $descriptif,
    'fichier'     =>  $fichier,
    'taille'      =>  (int) $f_size,
    'largeur'     =>  (int) $width,
    'hauteur'     =>  (int) $height,
    'mode'        =>  $mode,
    'distant'     =>  (_HTML2SPIP_PRESERVE_DISTANT ? "oui" : "non"),
    'maj'         =>  $date,
  );

  if (!($id = sql_insertq('spip_documents', $doc)))
    die("Failed SQL query: ". sql_error());

  return $id;
}

?>

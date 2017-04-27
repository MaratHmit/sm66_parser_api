<?php

namespace SE\Shop;

use SE\DB as DB;
use SE\Exception;
use Box\Spout\Reader\ReaderFactory;
use Box\Spout\Common\Type;

class Import extends Base
{
    private $dirFiles;
    private $skipRows = 10;
    private $idsRootGroups = array();

    function __construct($input)
    {
        parent::__construct($input);
        $this->dirFiles = DOCUMENT_ROOT . "/files";
        $this->dirImages = DOCUMENT_ROOT . "/images/rus/shopprice";
    }

    public function post()
    {
        $items = parent::post();
        if (count($items))
            $this->result = array("fileName" => $items[0]["name"]);
    }

    public function exec()
    {
        $cmd = $this->input["cmd"];
        $fileName = $this->input["fileName"];
        $filePath = "{$this->dirFiles}/{$fileName}";
        if ($cmd == "count") {
            $_SESSION["import"] = null;
            $_SESSION["retailPrice"] = (float)($this->input["retailPrice"] + 100);
            $_SESSION["corporatePrice"] = (float)($this->input["corporatePrice"] + 100);
            $_SESSION["wholesalePrice"] = (float)($this->input["wholesalePrice"] + 100);
            $_SESSION["updatePrices"] = $this->input["updatePrices"];
            $_SESSION["percent"] = 0;
            $_SESSION["countRows"] = $this->getCountRows($filePath);
            $this->result = array("countRows" => $_SESSION["countRows"]);
            return;
        }
        $this->result = $this->run();
    }

    public function report()
    {
        $db = new DB("shop_price", "sp");
        $db->select("sp.code, sp.article, sp.name, sp.price, sp.price_purchase, sp.price_opt, sp.price_opt_corp");
        $db->where("sp.marker_imp = 2");
        $goods = $db->getList();
        foreach ($goods as &$product)
            $product["link"] = "http://{$_SERVER['SERVER_NAME']}/catalogue/show/{$product["code"]}/#product";
        $this->result = array("goods" => $goods);
    }

    public function image()
    {
        $content = file_get_contents($this->dirFiles . "/goods_images.json");
        $data = json_decode($content, true);
        $product["img"] = null;

        if (count($data)) {
            $product = array_shift($data);
            $product["img"] = $this->getImage($product["article"]);
            if ($product["img"]) {
                $db = new DB("shop_price", "sp");
                $db->setValuesFields($product);
                $db->save();

                DB::query("INSERT IGNORE INTO shop_img (id_price, picture, picture_alt, title)
                               SELECT id, img, img_alt, img_alt FROM shop_price sp WHERE sp.img IS NOT NULL");
                DB::query("UPDATE shop_price SET enabled = 'Y' WHERE marker_imp > 1 AND img IS NOT NULL");
            }
            $_SESSION["import"]["indexImages"]++;

            $content = json_encode($data);
            file_put_contents($this->dirFiles . "/goods_images.json", $content);

        } else $_SESSION["import"]["indexImages"] = $_SESSION["import"]["countImages"];
        $this->result = array("percentImages" => $_SESSION["import"]["indexImages"], "image" => $product["img"]);
    }

    private function getCountRows($filePath)
    {
        $content = file_get_contents("zip://{$filePath}#xl/worksheets/sheet7.xml");
        file_put_contents("{$this->dirFiles}/sheet.xml", $content);
        $xml = simplexml_load_string($content);
        unset($content);
        $count = count($xml->sheetData->row);
        unset($xml);

        $content = file_get_contents("zip://{$filePath}#xl/sharedStrings.xml");
        file_put_contents("{$this->dirFiles}/strings.xml", $content);

        DB::query("UPDATE shop_price SET marker_imp = 1");

        return $count;
    }

    private function run()
    {
        $start = (int)round(($_SESSION["countRows"] / 100) * $_SESSION["percent"]);
        $end = (int)round(($_SESSION["countRows"] / 100) * ($_SESSION["percent"] + 1));
        if (++$_SESSION["percent"] >= 100)
            $end = $_SESSION["countRows"];

        $content = file_get_contents("{$this->dirFiles}/strings.xml");
        $xml = (array)simplexml_load_string($content);
        $sst = array();
        foreach ($xml['si'] as $item => $val)
            $sst[] = (string)$val->t;

        $db = new DB("shop_group", "sg");
        $db->select("sg.id");
        $db->where("sg.upid IS NULL");
        $groups = $db->getList();
        foreach ($groups as $group)
            $this->idsRootGroups[] = $group["id"];
        $this->idsRootGroups = implode(",", $this->idsRootGroups);

        $content = file_get_contents("{$this->dirFiles}/sheet.xml");
        $xml = simplexml_load_string($content);
        $rows = array();
        for ($i = $start; $i < $end; ++$i) {
            if ($i < $this->skipRows)
                continue;
            $row = $xml->sheetData->row[$i];
            $current = array();
            foreach ($row->c as $c) {
                $value = (string)$c->v;
                $attributes = $c->attributes();
                if ($attributes['t'] == 's') {
                    $current[] = $sst[$value];
                } else {
                    $current[] = $value;
                }
            }
            $rows[] = $current;
        }
        unset($content);
        unset($xml);

        foreach ($rows as $row) {
            if (!empty($row[0])) {
                if (empty($row[4]))
                    $this->importGroup($row);
                else $this->importProduct($row);
            }
        }

        if ($_SESSION["percent"] == 100) {
            /*
            DB::query("UPDATE shop_price SET enabled = 'N' WHERE marker_imp = 1");
            $d = new DB("shop_price", "sp");
            $d->select("sp.id, sp.article");
            $d->where("sp.img IS NULL AND sp.marker_imp > 1");
            $result = $d->getList();
            */
            $content = json_encode($result);
            file_put_contents($this->dirFiles . "/goods_images.json", $content);
            $_SESSION["import"]["countImages"] = count($result);
            return array(
                "percentGoods" => (int)$_SESSION["percent"],
                "countInsert" => (int)$_SESSION["import"]["countInsert"],
                "countUpdate" => (int)$_SESSION["import"]["countUpdate"],
                "countImages" => (int)$_SESSION["import"]["countImages"]
            );
        }

        return array("percentGoods" => $_SESSION["percent"]);
    }


    private function importProduct($row)
    {
        $data["article"] = $row[0];
        if (empty($data["article"]))
            return;

        $data["pricePurchase"] = (float)$row[5];
        $data["price"] = $data["pricePurchase"] * $_SESSION["retailPrice"] / 100;
        $data["priceOpt"] = $data["pricePurchase"] * $_SESSION["wholesalePrice"] / 100;
        $data["priceOptCorp"] = $data["pricePurchase"] * $_SESSION["corporatePrice"] / 100;
        if ($row[8]) {
            if ($row[8] != trim("Хит продаж!"))
                $data["flagNew"] = "Y";
            else $data["flagHit"] = "Y";
        }

        try {
            $db = new DB("shop_price", "sp");
            $db->select("sp.id, sp.img, flag_hit, flag_new, sdl.discount_id, special_price");
            $db->leftJoin("shop_discount_links sdl", "sp.id = sdl.id_price");
            $db->where("sp.article = '?'", $data["article"]);
            $result = $db->fetchOne();

            if (empty($result)) {
                $data["idGroup"] = $_SESSION["import"]["idGroup"];
                $data["code"] = $row[0];
                $data["name"] = $row[1];
                $data["idBrand"] = $this->getIdBrand($row[3]);
                $data["imgAlt"] = $data["name"];
                $data["title"] = $data["name"];
                $data["markerImp"] = 2;
                $_SESSION["import"]["countInsert"]++;
            } else {
                if (!$_SESSION["updatePrices"] &&
                    ($result["flagHit"] == "Y" || $result["flagNew"] == "Y" || $result["discountId"])) {
                    unset($data["flagHit"]);
                    unset($data["specialPrice"]);
                    unset($data["price"]);
                    unset($data["priceOpt"]);
                    unset($data["priceOptCorp"]);
                }

                $data["id"] = $result["id"];
                $data["idGroup"] = $_SESSION["import"]["idGroup"];
                $data["markerImp"] = 3;
                $_SESSION["import"]["countUpdate"]++;
            }

            $db = new DB("shop_price", "sp");
            $db->setValuesFields($data);
            $data["id"] = $db->save();

            if ($data["idGroup"]) {
                $db = new DB("shop_price_group", "spg");
                $db->select("spg.id, spg.id_group");
                $db->where("spg.id_price = ?", $data["id"]);
                $result = $db->fetchOne();
                if (empty($result)) {
                    $db = new DB("shop_price_group", "spg");
                    $db->setValuesFields(array(
                        "idGroup" => $data["idGroup"],
                        "idPrice" => $data["id"],
                        "isMain" => true
                    ));
                    $db->save();
                }

            }

            $_SESSION["import"]["isGroup"] = false;
        } catch (Exception $e) {
            $this->result = "Не удается сохранить товар: {$data['name']}!";
        }
    }

    private function importGroup($row)
    {
        if (empty($row[0]) || empty($row[1]))
            return;

        $data["name"] = trim($row[1]);

        $db = new DB("shop_group", "sg");
        $db->select("sg.id, sg.upid, sg.name");
        $db->where("name = '?'", $data["name"]);
        /*
        if ($_SESSION["import"]["idGroupParent"])
            $db->andWhere("upid = ?", $_SESSION["import"]["idGroupParent"]);
        else $db->andWhere("upid IN ($this->idsRootGroups)");
        */
        $result = $db->fetchOne();
        if (empty($result)) {
            $data["upid"] = null;
            if ($_SESSION["import"]["isGroup"]) {
                $db = new DB("shop_group", "sg");
                $db->setValuesFields(array("id" => $_SESSION["import"]["idGroup"], "upid" => null));
                $db->save();
                $_SESSION["import"]["idGroupParent"] = $_SESSION["import"]["idGroup"];
            }
            if ($_SESSION["import"]["idGroupParent"])
                $data["upid"] = $_SESSION["import"]["idGroupParent"];

            $db = new DB("shop_group", "sg");
            $data["codeGr"] = strtolower(se_translite_url($data["name"]));
            $data["codeGr"] = $this->getCodeGroup($data["codeGr"]);
            $db->setValuesFields($data);
            $data["id"] = $db->save();
            self::saveIdParent($data["id"], $data["upid"]);

        } else {
            $data["id"] = $result["id"];
            $_SESSION["import"]["idGroupParent"] = $result["upid"];
            if (!$_SESSION["import"]["isGroup"])
                $_SESSION["import"]["idGroupParent"] = $data["id"];

        }
        $_SESSION["import"]["isGroup"] = true;
        $_SESSION["import"]["idGroup"] = $data["id"];
    }

    private function getCodeGroup($code)
    {
        $code_n = $code;
        $u = new DB('shop_group', 'sg');
        $i = 1;
        while ($i < 100) {
            $data = $u->findList("sg.code_gr = '$code_n'")->fetchOne();
            if ($data["id"])
                $code_n = $code . "-$i";
            else return $code_n;
            $i++;
        }
        return uniqid();
    }


    static public function getLevel($id)
    {
        $level = 0;
        $sqlLevel = 'SELECT `level` FROM shop_group_tree WHERE id_parent = :id_parent AND id_child = :id_parent LIMIT 1';
        $sth = DB::prepare($sqlLevel);
        $params = array("id_parent" => $id);
        $answer = $sth->execute($params);
        if ($answer !== false) {
            $items = $sth->fetchAll(\PDO::FETCH_ASSOC);
            if (count($items))
                $level = $items[0]['level'];
        }
        return $level;
    }

    static public function saveIdParent($id, $idParent)
    {
        try {
            $idParent = intval($idParent);
            $u = new DB('shop_group_tree');
            $u->select('id');
            $u->where('id_child = ?', $id);
            if ($idParent) {
                $u->andWhere('id_parent = ?', $idParent);
            } else {
                $u->andWhere('level = 0');
            }
            $answer = $u->fetchOne();
            if (empty($answer)) {
                $level = 0;
                DB::query("DELETE FROM shop_group_tree WHERE id_child = {$id}");

                $sqlGroupTree = "INSERT INTO shop_group_tree (id_parent, id_child, `level`)
                                SELECT id_parent, :id, :level FROM shop_group_tree
                                WHERE id_child = :id_parent
                                UNION ALL
                                SELECT :id, :id, :level";
                $sthGroupTree = DB::prepare($sqlGroupTree);
                if (!empty($idParent)) {
                    $level = self::getLevel($idParent);
                    $level++;
                }
                $sthGroupTree->execute(array('id_parent' => $idParent, 'id' => $id, 'level' => $level));
            }
        } catch (Exception $e) {
            throw new Exception("Не удаётся сохранить родителя группы!");
        }
    }

    private function getIdBrand($name)
    {
        $name = trim($name);
        if (empty($name))
            return null;

        $db = new DB("shop_brand", "sb");
        $db->select("sb.id");
        $db->where("sb.name = '?'", $name);
        $result = $db->fetchOne();
        if (empty($result)) {
            $db = new DB("shop_brand", "sb");
            $data["name"] = $name;
            $data["code"] = strtolower(se_translite_url($name));
            $db->setValuesFields($data);
            return $db->save();
        } else return $result["id"];
    }

    private function getImage($code)
    {
        $result = null;
        $url = "http://www.samsonopt.ru/ajax/detail_img.php?CODE={$code}&TYPE=IMG";
        $file = md5($code) . ".jpg";
        $pathFile = $this->dirImages . "/{$file}";
        if (file_exists($pathFile)) {
            $image = file_get_contents($pathFile);
            if (!empty($image) && strlen($image) > 128)
                return $file;
        }

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.3; WOW64; rv:42.0) Gecko/20100101 Firefox/42.0');
        $image = curl_exec($curl);
        curl_close($curl);

        if (!empty($image) && strlen($image) > 128) {
            $result = $file;
            file_put_contents($this->dirImages . "/{$result}", $image);
        }

        return $result;
    }


}
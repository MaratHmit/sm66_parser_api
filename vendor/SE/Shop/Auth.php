<?php

namespace SE\Shop;

use SE\DB as DB;
use SE\Exception;

class Auth extends Base
{

    public function getAuthData($data = array())
    {
        $url = AUTH_SERVER . "/api/2/Auth/Register.api";
        $ch = curl_init($url);
        $data["project"] =  "sm66";
        $apiData = json_encode($data);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
        curl_setopt($ch, CURLOPT_POSTFIELDS, $apiData);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json',
                'Content-Length: ' . strlen($apiData))
        );
        $result = json_decode(curl_exec($ch), 1);
        if ($result["status"] == "ok")
            return $result["data"];
        return null;
    }


    public function info($id = null)
    {
        $authData = $this->getAuthData($this->input);
        if (!$authData) {
            $this->error = "Проект не найден или не активен!";
            return null;
        }

        try {
            if ($authData["isAdmin"]) {
                $data['userDisplay'] = 'Администратор';
                $data['isAdmin'] = true;
            } else {
                $u = new DB("se_user", "su");
                $u->select('su.id,
                    CONCAT_WS(" ", p.last_name, CONCAT_WS(".", SUBSTR(p.first_name, 1, 1), SUBSTR(p.sec_name, 1, 1))) displayName');
                $u->innerJoin('person p', 'p.id=su.id');
                $u->where('is_active="Y" AND username="?"', $this->input["login"]);
                $u->andWhere('password="?"', strtolower($this->input["hash"]));
                $result = $u->fetchOne();
                if (!empty($result)) {
                    $data['userDisplay'] = $result["displayName"];
                    $data['idUser'] = $result["id"];
                } else {
                    $this->error = 'Неправильное имя пользователя или пароль!';
                    throw new Exception($this->error);
                }
            }

            if (!empty($this->error))
                return $this;

            $u = new DB("main", "m");
            $u->select("*");
            $u->orderBy("id");

            $data['hostname'] = $this->hostname;

            $authData["login"] = $this->input["login"];
            $authData["hash"] = $this->input["hash"];
            $data['config'] = $authData;

            $_SESSION["login"] = $this->input["login"];
            $_SESSION["hash"] = $this->input["hash"];
            $_SESSION['idUser'] = $data['idUser'];
            $_SESSION['isAuth'] = true;
            $_SESSION['hostname'] = HOSTNAME;

            $this->result = $data;

        } catch (Exception $e) {
            $this->error = "Ошибка при авторизации!";
        }
    }

    public function get()
    {
        $this->result["permissions"] = array();
    }
}
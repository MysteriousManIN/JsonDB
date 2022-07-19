<?php


    /*!

        JsonDB (JavaScript Object Notation DataBase)
        @version 1

        @author Chetan Saini, aka Mysterious Man

    */

    declare(strict_types = 1);

    namespace chetansaini\JsonDB;

    trait private_task{

        private function check_datatype($value){

            $type = gettype($value);

            if($type === "integer" || $type === "double") return "number";
            if($type === "string" ) return "string";
            if($type === "boolean") return "boolean";
            if($type === "NULL") return "null";
            if($type === "array"){

                $keys = array_keys($value);
                $integer = 0; $string = 0;

                foreach($keys as $k){

                    $k = gettype($k);
                    if($k === "integer") $integer++;
                    if($k === "string" ) $string++;

                }

                if(count($keys) === $integer) return "array";
                if(count($keys) === $string){

                    $value = json_encode($value);
                    if($value) return "object";

                }

            }

            return false; // for invalid datatype

        }

        private function write_into_db(): bool{

            if(count($this->db_data) === 0) $json = "{}";
            else $json = json_encode($this->db_data, JSON_PRETTY_PRINT);

            $res = file_put_contents($this->db_name, $json);

            return $res ? true : false;

        }

        // return dummy row, unique columns, not null columns, and auto increment columns
        private function dr_uc_nnc_aic(string $table_name): array{

            $columns = $this->db_data[$table_name]["structure"]["columns"];

            $dummy_row = array();
            $unique_cols = array();
            $not_null_cols = array();
            $auto_increment_cols = array();

            foreach($columns as $col){
                $dummy_row[$col["name"]] = null;
                if($col["unique"]) array_push($unique_cols, $col["name"]);
                if($col["not_null"]) array_push($not_null_cols, $col["name"]);
                if($col["auto_increment"]) array_push($auto_increment_cols, $col["name"]);
            }

            return array(
                "dummy_row" => $dummy_row,
                "unique_cols" => $unique_cols,
                "not_null_cols" => $not_null_cols,
                "auto_increment_cols" => $auto_increment_cols
            );

        }

        // where clause
        private function where(array $where, string $table_name, string $cols): array{

            [ $col_name, $col_value, $operator ] = $where;

            $exists_rows = $this->db_data[$table_name]["rows"];

            if(!isset($exists_rows[0][$col_name]))
            throw new \Error("'$col_name' column does not exists in '$table_name' table");

            $datatype = $this->check_datatype($exists_rows[0][$col_name]);
            if($datatype !== $this->check_datatype($col_value))
            throw new \Error("Wrong datatype of '$col_name' column's value in where clause");

            $unique_cols = $this->dr_uc_nnc_aic($table_name)["unique_cols"];
            $rows = array();

            $times = false;
            if($operator === "="){
                if(in_array($col_name, $unique_cols)) $times = true;
            }

            $rows = $this->search_into_table($exists_rows, $col_name, $col_value, $operator, $cols, $times);
            
            return $rows;

        }

        private function search_into_table(array $rows, string $col_name, $col_value, string $operator, string $cols, bool $times = false): array{

            $operator = in_array($operator, $this->where_clause_operators) ? $operator : "=";
            $res_rows = array();

            foreach($rows as $row_idx => $row){
                if(isset($row[$col_name])){
                    
                    $a = $row[$col_name];
                    $b = $col_value;

                    switch($operator){
                        case "=": $c = $a === $b; break;
                        case ">": $c = $a > $b; break;
                        case "<": $c = $a < $b; break;
                        case ">=": $c = $a >= $b; break;
                        case "<=": $c = $a <= $b; break;
                        case "!=": $c = $a !== $b; break;
                    }

                    if($c){

                        if($cols === "*") array_push($res_rows, $row);
                        else{
                            $filtered_row = array();
                            foreach(explode(",", $cols) as $c_n){
                                if(isset($row[$c_n])) $filtered_row[$c_n] = $row[$c_n];
                            }
                            array_push($res_rows, $filtered_row);
                        }
                        
                        if($times) break;

                    }

                }
            }

            return $res_rows;

        }

        private function array_diff_count(array $a, array $b): int{

            $count = 0;

            foreach($a as $k => $v){
                if(isset($b[$k])){
                    if($a[$k] !== $b[$k]) $count++;
                }
            }

            return $count;

        }

    }

    trait create{

        // create and connect with a jsondb
        public function create_db(string $db_name): bool{

            if(is_file($db_name))
            throw new \Error("'$db_name' jsondb already exists");

            $json = "{}";
            $res = file_put_contents($db_name, $json);

            if($res){

                $this->db_name = $db_name;
                $this->db_data = array();

                return true;

            }else return false;

        }

        // create a table into jsondb
        public function create_table(string $table_name, array $table_struct, bool $if_not_exists = false): bool{

            if(isset($this->db_data[$table_name])){
                if($if_not_exists) return true;
                else throw new \Error("'$table_name' table already exists into '$this->db_name' jsondb");
            }

            if(!isset($table_struct["columns"]))
            throw new \Error("'columns' is not set in '$table_name' table's structure");

            $columns = $table_struct["columns"];
            foreach($columns as $col_idx => $col){

                foreach($this->column_properties as $property){

                    if(isset($col[$property])){

                        // datatype of value of column property 
                        $datatype = $this->check_datatype($col[$property]);

                        switch($property){

                            case "name": {

                                if($datatype !== "string")
                                throw new \Error("Datatype of value of '$property' property of column is not 'string' of '$table_name' table's column at index $col_idx");

                            } break;

                            case "datatype": {

                                if($datatype !== "string")
                                throw new \Error("Datatype of value of '$property' property of column is not 'string' of '$table_name' table's column at index $col_idx");

                                if(!in_array($col["datatype"], $this->valid_datatype))
                                throw new \Error("Invalid datatype of '$table_name' table's column at index $col_idx");

                            } break;

                            case "default": {

                                if($col["datatype"] !== $datatype)
                                throw new \Error("Datatype of value of 'default' property is not matching with value of 'datatype' property of '$table_name' table's column at index $col_idx");

                            } break;

                            case "unique": {

                                if($datatype !== "boolean")
                                throw new \Error("Datatype is invalid of value of '$property' property in $table_name' table's column at index $col_idx");

                                if($col["unique"]) $col["not_null"] = true;

                            } break;

                            case "not_null" : {

                                if($datatype !== "boolean")
                                throw new \Error("Datatype is invalid of value of '$property' property in $table_name' table's column at index $col_idx");

                            } break;

                            case "auto_increment": {

                                if($datatype !== "boolean")
                                throw new \Error("Datatype is invalid of value of '$property' property in $table_name' table's column at index $col_idx");

                                if($col["datatype"] !== "number")
                                throw new \Error("Value of 'datatype' property is not 'number' for 'auto_increment' column of '$table_name' table's column at index $col_idx");
                            
                                if($col["auto_increment"]){
                                    $col["default"] = null;
                                    $col["unique"] = true;
                                    $col["not_null"] = true;
                                }

                            } break;

                        }

                    }else{

                        if(in_array($property, array("name", "datatype")))
                        throw new \Error("'$property' property of column is not set in '$table_name' table's column at index $col_idx");

                        $col[$property] = null;

                    }

                }

                // rearrange a column
                $rearranged_col = array();
                foreach($this->column_properties as $property) $rearranged_col[$property] = $col[$property];

                $columns[$col_idx] = $rearranged_col;

            }

            // if primary key is set
            if(isset($table_struct["primary_key"])){

                $primary_key = $table_struct["primary_key"];
                $type = $this->check_datatype($primary_key);

                if(!in_array($type, array("string", "null")))
                throw new \Error("Datatype is invalid of value of 'primary_key' property of '$table_name' table");

                if($primary_key !== null){

                    $cols = array_map(function($col){
                        return $col["name"];
                    }, $columns);
    
                    $pos = array_search($primary_key, $cols);
                    if($pos === false)
                    throw new \Error("Column '$primary_key' is not exists for primary_key in table '$table_name'");
    
                    $columns[$pos]["default"] = null;
                    $columns[$pos]["unique"] = true;
                    $columns[$pos]["not_null"] = true;

                }
                
            }else{
                $primary_key = null;
            }

            // create valid structure
            $structure = array(
                "columns" => $columns,
                "primary_key" => $primary_key
            );

            $ary = array(
                "rows" => array(),
                "structure" => $structure
            );

            $this->db_data[$table_name] = $ary;

            return $this->write_into_db();

        }

        // create a row into a table 
        public function insert_into(string $table_name, array $row): bool{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $dr_uc_nnc_aic = $this->dr_uc_nnc_aic($table_name);
            $not_null_cols = $dr_uc_nnc_aic["not_null_cols"];
            $auto_increment_cols = $dr_uc_nnc_aic["auto_increment_cols"];

            $diff = array_diff($not_null_cols, array_keys($row));
            $diff = array_diff($diff, $auto_increment_cols);
            $diff_size = count($diff);

            // for not null column
            if($diff_size !== 0){
                $diff = implode(",", $diff); 
                throw new \Error("Fill the value of not_null column ($diff) of '$table_name' table");
            }

            $dummy_row = $dr_uc_nnc_aic["dummy_row"];
            $unique_cols = $dr_uc_nnc_aic["unique_cols"];
            $required_cols = array_keys($dummy_row); // valid columns

            $exists_rows = $this->db_data[$table_name]["rows"];

            foreach($row as $col_name => $value){

                $pos = array_search($col_name, $required_cols);
                if($pos !== false){

                    $datatype = $this->db_data[$table_name]["structure"]["columns"][$pos]["datatype"];
                    if($datatype !== $this->check_datatype($value))
                    throw new \Error("Wrong datatype of value of '$col_name' column into row of '$table_name' table");

                    if(in_array($col_name, $unique_cols)){

                        $unique_values = array();
                        foreach($exists_rows as $er){
                            array_push($unique_values, $er[$col_name]);
                        }

                        if(in_array($value, $unique_values))
                        throw new \Error("Duplicate entry at '$col_name' column of '$table_name' table");

                        $dummy_row[$col_name] = $value;

                    }else if(in_array($col_name, $auto_increment_cols)){

                        $exists_rows_size = count($exists_rows);
                        if($exists_rows_size === 0) $dummy_row[$col_name] = 1;
                        else $dummy_row[$col_name] = $exists_rows[$exists_rows_size - 1][$col_name] + 1;

                    }else{

                        $dummy_row[$col_name] = $value;

                    }

                }else throw new \Error("Invalid '$col_name' column into row of '$table_name' table");

            }

            // fill auto_increment column's value and default value
            foreach($dummy_row as $col_name => $value){

                if(in_array($col_name, $auto_increment_cols)){ // for auto_increment

                    $exists_rows_size = count($exists_rows);
                    if($exists_rows_size === 0) $dummy_row[$col_name] = 1;
                    else $dummy_row[$col_name] = $exists_rows[$exists_rows_size - 1][$col_name] + 1;

                }

                // default value
                $pos = array_search($col_name, $required_cols);
                if($pos !== false){

                    $default_value = $this->db_data[$table_name]["structure"]["columns"][$pos]["default"];
                    if($dummy_row[$col_name] === null) $dummy_row[$col_name] = $default_value;

                }

            }

            $row = $dummy_row;
            array_push($this->db_data[$table_name]["rows"], $row);
            
            return $this->write_into_db();

        }

    }

    trait read{

        // read rows from table
        public function select_from(string $table_name, string $cols = "*", array $where = []): array{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $where_size = count($where);
            $exists_rows = $this->db_data[$table_name]["rows"];
            $rows = array();

            if($where_size === 0){

                if($cols === "*"){
                    $rows = $exists_rows;
                }else{
    
                    foreach($exists_rows as $row_idx => $row){
    
                        $temp_row = array();
                        foreach(explode(",", $cols) as $col_name){
                            if(isset($row[$col_name])) $temp_row[$col_name] = $row[$col_name];
                        }
                        array_push($rows, $temp_row);
    
                    }
    
                }

            }else if($where_size === 3){

                $rows = $this->where($where, $table_name, $cols);

            }else throw new \Error("Size of where clause is not equal to 3 for '$table_name' table");

            return $rows;

        }

        // get available table form jsondb
        public function available_table(): array{

            if(!is_file($this->db_name)) throw new \Error("'$this->db_name' jsondb does not exists");

            $table = array_keys($this->db_data);
            
            return $table;

        }

    }

    trait update{

        // update values 
        public function update_table(string $table_name, array $sets, array $where = []): bool{

            $exists_rows = $this->db_data[$table_name]["rows"];
            $rows = $this->select_from($table_name, "*", $where);

            if(count($rows) === 0) return true;

            $dr_uc_nnc_aic = $this->dr_uc_nnc_aic($table_name);
            $unique_cols = $dr_uc_nnc_aic["unique_cols"];
            $not_null_cols = $dr_uc_nnc_aic["not_null_cols"];
            $auto_increment_cols = $dr_uc_nnc_aic["auto_increment_cols"];

            foreach($sets as $col_name => $col_value){

                if(!isset($rows[0][$col_name]))
                throw new \Error("'$col_name' column does not exists in '$table_name' table");

                $datatype = $this->check_datatype($rows[0][$col_name]);
                if($col_value !== null && $datatype !== $this->check_datatype($col_value))
                throw new \Error("Wrong datatype of '$col_name' column's value of '$table_name' table");

                if(in_array($col_name, $not_null_cols)){
                    if($col_value === null)
                    throw new \Error("Datatype of '$col_name' column's value can not be null");
                }

                if(in_array($col_name, $unique_cols)){

                    if(count($rows) !== 1)
                    throw new \Error("'$col_name' column is unique of '$table_name' table");

                    $unique_value = array();
                    foreach($exists_rows as $r){
                        array_push($unique_value, $r[$col_name]);
                    }
    
                    if(in_array($col_value, $unique_value))
                    throw new \Error("Duplicate entry at '$col_name' column of '$table_name' table");
    
                }

                if(in_array($col_name, $auto_increment_cols))
                throw new \Error("Does not update the value of '$col_name' auto_increment column of '$table_name' table");
            
                foreach($rows as $i => $r){
                    $rows[$i][$col_name] = $col_value;
                }

            }

            foreach($rows as $r){
                foreach($exists_rows as $i => $er){

                    $diff_count = $this->array_diff_count($er, $r);
                    if($diff_count === count($sets)){
                        $exists_rows[$i] = $r;
                        break;
                    }

                }
            }

            $this->db_data[$table_name]["rows"] = $exists_rows;

            return $this->write_into_db();

        }

    }

    trait delete{

        // delete row/rows from table
        public function delete_from(string $table_name, array $where = []): bool{

            if(count($where) === 0) return $this->truncate_table($table_name);

            $rows = $this->select_from($table_name, "*", $where);
            
            if(count($rows) === 0) return true;
            
            $exists_rows = $this->db_data[$table_name]["rows"];
            
            foreach($exists_rows as $i => $er){
                foreach($rows as $r){
                    $diff = $this->array_diff_count($er, $r);
                    if($diff === 0){
                        unset($exists_rows[$i]);
                        break;
                    }
                }
            }
            
            $new_rows = array();
            foreach($exists_rows as $er) array_push($new_rows, $er);

            $this->db_data[$table_name]["rows"] = $new_rows;

            return $this->write_into_db();

        }

        // delete the jsondb
        public function drop_db(): bool{

            if(isset($this->db_name)){

                unlink($this->db_name);
                return true;

            }else return false;

        }

        // delete table from jsondb
        public function drop_table(string $table_name): bool{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            unset($this->db_data[$table_name]);

            return $this->write_into_db();

        }

        // delete all rows from table of jsondb
        public function truncate_table(string $table_name): bool{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $this->db_data[$table_name]["rows"] = array();

            return $this->write_into_db();

        }
    }

    class JsonDB{

        use private_task, create, read, update, delete;

        private $db_name;
        private $db_data;

        private $valid_datatype = array( "string", "number", "boolean", "object", "array", "null" );
        private $column_properties = array( "name", "datatype", "default", "unique", "not_null", "auto_increment" );
        private $where_clause_operators = array( "=", ">", "<", ">=", "<=", "!=" );

        function __construct(string $db_name = ""){

            if($db_name !== ""){

                $this->connect_db($db_name);

            }

        }

        // connect with the jsondb
        public function connect_db(string $db_name){

            if(is_file($db_name)){

                $this->db_name = $db_name;

                $json = file_get_contents($db_name);
                $this->db_data = json_decode($json, true);

            }else throw new \Error("'$db_name' jsondb does not exists");

        }

    }


?>

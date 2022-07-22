<?php

    /*! JsonDB v1 | Developed by Chetan Saini */
    
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

        private function write_into_db(): int{

            if(count($this->db_data) === 0) $json = "{}";
            else $json = json_encode($this->db_data, JSON_PRETTY_PRINT);

            $db_data_size = strlen($json);

            // when size is out of limits
            if(!($db_data_size >= $this->db_limits["db_size"]["min"] && $db_data_size <= $this->db_limits["db_size"]["max"])){
                $this->db_data = $this->db_data_clone;
                return -1;
            }

            $res = file_put_contents($this->db_name, $json) ? 1 : 0;

            if($res === 1) $this->db_data_clone = $this->db_data;

            return $res;

        }

        // return dummy row, unique columns, not null columns, and auto increment columns
        private function dr_uc_nnc_aic(string $table_name): array{

            $columns = $this->db_data[$table_name]["structure"]["columns"];

            $dummy_row = [];
            $unique_cols = [];
            $not_null_cols = [];
            $auto_increment_cols = [];

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

        private function get_col_name_with_dt(string $table_name): array{

            $exists_columns = $this->db_data[$table_name]["structure"]["columns"];

            $col_name_datatype = [];
            foreach($exists_columns as $col_idx => $col) $col_name_datatype[$col["name"]] = $col["datatype"];

            return $col_name_datatype;

        }

        // where clause
        private function where(array $where, string $table_name, string $cols): array{

            [ $col_name, $col_value, $operator ] = $where;

            $exists_rows = $this->db_data[$table_name]["rows"];
            $col_name_datatype = $this->get_col_name_with_dt($table_name);
            
            if(in_array($col_name, array_keys($col_name_datatype))){

                $datatype = $col_name_datatype[$col_name];
                if($datatype !== $this->check_datatype($col_value))
                throw new \Error("Wrong datatype of '$col_name' column's value in where clause");

            }else throw new \Error("'$col_name' column does not exists in '$table_name' table");

            $unique_cols = $this->dr_uc_nnc_aic($table_name)["unique_cols"];
            $rows = [];

            $times = false;
            if($operator === "="){
                if(in_array($col_name, $unique_cols)) $times = true;
            }

            $rows = $this->search_into_table($exists_rows, $col_name, $col_value, $operator, $cols, $times);
            
            return $rows;

        }

        private function search_into_table(array $rows, string $col_name, $col_value, string $operator, string $cols, bool $times = false): array{

            $operator = in_array($operator, $this->where_clause_operators) ? $operator : "=";
            $res_rows = [];

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

                        if($cols === "*") $res_rows[$row_idx] = $row;
                        else{
                            $filtered_row = [];
                            foreach(explode(",", $cols) as $c_n){
                                if(isset($row[$c_n])) $filtered_row[$c_n] = $row[$c_n];
                            }
                            $res_rows[$row_idx] = $filtered_row;
                        }
                        
                        if($times) break;

                    }

                }
            }
            
            return $res_rows;

        }

        // add columns temporary into table
        private function add_column(string $table_name, array $columns){

            $exists_rows = $this->db_data[$table_name]["rows"];
            $exists_columns = $this->db_data[$table_name]["structure"]["columns"];
            $columns_name = array_keys($this->dr_uc_nnc_aic($table_name)["dummy_row"]);

            foreach($columns as $col_idx => $col){

                foreach($this->column_properties as $property){

                    if(isset($col[$property])){

                        // datatype of value of column property 
                        $datatype = $this->check_datatype($col[$property]);

                        switch($property){

                            case "name": {

                                if($datatype !== "string")
                                throw new \Error("Datatype of value of '$property' property of column is not 'string' of '$table_name' table's column at index $col_idx");

                                $col_name_len = strlen($col["name"]);
                                if(!($col_name_len >= $this->db_limits["table_limits"]["col_name"]["min"] && $col_name_len <= $this->db_limits["table_limits"]["col_name"]["max"]))
                                throw new \Error("Length of column name at index $col_idx into '$table_name' tables is out of limits");

                                if(in_array($col["name"], $columns_name))
                                throw new \Error("Duplicate '{$col["name"]}' column entry into '$table_name' table");

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

                                if($datatype === "string"){
                                    $default_val_len = strlen($col["default"]);
                                    if(!($default_val_len >= $this->db_limits["datatype"]["string"]["min"] && $default_val_len <= $this->db_limits["datatype"]["string"]["max"]))
                                    throw new \Error("Length of default value at index $col_idx into '$table_name' tables is out of limits");
                                }

                            } break;

                            case "unique": {

                                if($datatype !== "boolean")
                                throw new \Error("Datatype is invalid of value of '$property' property in $table_name' table's column at index $col_idx");

                                if($col["unique"]){ 
                                    $col["default"] = null;
                                    $col["not_null"] = true;
                                }

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
                $rearranged_cols = [];
                foreach($this->column_properties as $property) $rearranged_cols[$property] = $col[$property];

                $columns[$col_idx] = $rearranged_cols;

                [ "name"=> $col_name, "default"=> $default_value ] = $rearranged_cols;

                foreach($exists_rows as $row_idx => $row) $exists_rows[$row_idx][$col_name] = $default_value;

                array_push($columns_name, $rearranged_cols["name"]);

            }

            array_push($exists_columns, ...$columns);

            $this->db_data[$table_name]["rows"] = $exists_rows;
            $this->db_data[$table_name]["structure"]["columns"] = $exists_columns;

        }

        // remove columns from table
        private function remove_column(string $table_name, array $columns){

            $exists_columns_name = array_keys($this->dr_uc_nnc_aic($table_name)["dummy_row"]);
            $exists_rows = $this->db_data[$table_name]["rows"];
            $exists_columns = $this->db_data[$table_name]["structure"]["columns"];
            $primary_key = $this->db_data[$table_name]["structure"]["primary_key"];

            foreach($columns as $col_name){

                $pos = array_search($col_name, $exists_columns_name);
                if($pos > -1){

                    if($col_name === $primary_key)
                    throw new \Error("'$col_name' column is primary key, so it can not removed from '$table_name' table");

                    unset($exists_columns[$pos]);

                    foreach($exists_rows as $row_idx => $row){

                        unset($exists_rows[$row_idx][$col_name]);

                    }

                }else throw new \Error("'$col_name' column does not exists into '$table_name' table");

            }

            $rearranged_cols = [];
            foreach($exists_columns as $col) array_push($rearranged_cols, $col);

            $rearranged_rows = [];
            foreach($exists_rows as $row) array_push($rearranged_rows, $row);

            $this->db_data[$table_name]["rows"] = $rearranged_rows;
            $this->db_data[$table_name]["structure"]["columns"] = $rearranged_cols;

        }

    }

    trait create{

        // create and connect with a jsondb
        public function create_db(string $db_name): int{

            if(is_file($db_name))
            throw new \Error("'$db_name' jsondb already exists");

            $json = "{}";
            $res = file_put_contents($db_name, $json);

            if($res){

                $this->db_name = $db_name;
                $this->db_data = [];
                $this->db_data_clone = $this->db_data;

                return 1;

            }else return 0;

        }

        // create a table into jsondb
        public function create_table(string $table_name, array $table_struct, bool $if_not_exists = false): int{

            if(isset($this->db_data[$table_name])){
                if($if_not_exists) return 1;
                else throw new \Error("'$table_name' table already exists into '$this->db_name' jsondb");
            }

            $num_of_table = count($this->db_data) + 1;
            if(!($num_of_table >= $this->db_limits["num_of_table"]["min"] && $num_of_table <= $this->db_limits["num_of_table"]["max"]))
            throw new \Error("Number of tables are out of limits into '$this->db_name' jsondb");

            if(!isset($table_struct["columns"]))
            throw new \Error("'columns' is not set in '$table_name' table's structure");

            $columns = $table_struct["columns"];

            $num_of_cols = count($columns);
            if(!($num_of_cols >= $this->db_limits["table_limits"]["cols"]["min"] && $num_of_cols <= $this->db_limits["table_limits"]["cols"]["max"]))
            throw new \Error("Number of columns into '$table_name' table are out of limits into '$this->db_name' jsondb");

            // define blank table structure
            $this->db_data[$table_name] = [
                "rows"=> [],
                "structure"=> [
                    "columns"=> [],
                    "primary_key"=> null
                ]
            ];

            $this->add_column($table_name, $columns);

            // if primary key is set
            if(isset($table_struct["primary_key"])){

                $primary_key = $table_struct["primary_key"];
                $type = $this->check_datatype($primary_key);

                if(!in_array($type, array("string", "null")))
                throw new \Error("Datatype is invalid of value of 'primary_key' property of '$table_name' table");

                if($primary_key !== null){

                    $cols = array_map(function($col){
                        return $col["name"];
                    }, $this->db_data[$table_name]["structure"]["columns"]);
    
                    $pos = array_search($primary_key, $cols);
                    if($pos === false)
                    throw new \Error("Column '$primary_key' is not exists for primary_key in table '$table_name'");
    
                    $this->db_data[$table_name]["structure"]["columns"][$pos]["default"] = null;
                    $this->db_data[$table_name]["structure"]["columns"][$pos]["unique"] = true;
                    $this->db_data[$table_name]["structure"]["columns"][$pos]["not_null"] = true;

                }

                $this->db_data[$table_name]["structure"]["primary_key"] = $primary_key;
                
            }

            return $this->write_into_db();

        }

        // create a row into a table, ($bulk === true) for insert multiple rows at a time 
        public function insert_into(string $table_name, array $row, bool $bulk = false): int{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $dr_uc_nnc_aic = $this->dr_uc_nnc_aic($table_name);
            $not_null_cols = $dr_uc_nnc_aic["not_null_cols"];
            $auto_increment_cols = $dr_uc_nnc_aic["auto_increment_cols"];

            $dummy_row = $dr_uc_nnc_aic["dummy_row"];
            $unique_cols = $dr_uc_nnc_aic["unique_cols"];
            $required_cols = array_keys($dummy_row); // valid columns

            $exists_rows = $this->db_data[$table_name]["rows"];

            if($bulk){ 

                if($this->check_datatype($row) !== "array")
                throw new \Error("Datatype of 'row' paramter is not indexed array for insert multiple rows at a time into '$table_name' table");

                $rows = $row;

            }else{
                
                if($this->check_datatype($row) !== "object")
                throw new \Error("Datatype of 'row' paramter is not associative array for insert single row at a time into '$table_name' table");

                $rows = array( $row );

            }

            $size_of_rows = count($exists_rows) + count($rows);
            if(!($size_of_rows >= $this->db_limits["table_limits"]["rows"]["min"] && $size_of_rows <= $this->db_limits["table_limits"]["rows"]["max"]))
            throw new \Error("Number of rows into '$table_name' table are out of limits into '$this->db_name' jsondb");

            foreach($rows as $row_idx => $row){

                $diff = array_diff($not_null_cols, array_keys($row));
                $diff = array_diff($diff, $auto_increment_cols);
                $diff_size = count($diff);

                // for not null column
                if($diff_size !== 0){
                    $diff = implode(",", $diff); 
                    throw new \Error("Fill the value of not_null column ($diff) of '$table_name' table at row index $row_idx");
                }

                foreach($row as $col_name => $value){

                    $pos = array_search($col_name, $required_cols);
                    if($pos !== false){
    
                        $datatype = $this->db_data[$table_name]["structure"]["columns"][$pos]["datatype"];
                        $value_datatype = $this->check_datatype($value);
    
                        if($datatype !== $value_datatype)
                        throw new \Error("Wrong datatype of value of '$col_name' column into row of '$table_name' table");
    
                        if($value_datatype === "string"){
                            $val_len = strlen($value);
                            if(!($val_len >= $this->db_limits["datatype"]["string"]["min"] && $val_len <= $this->db_limits["datatype"]["string"]["max"]))
                            throw new \Error("Length of value of '$col_name' column at index $row_idx into '$table_name' tables is out of limits");
                        }
                        
                        if(in_array($col_name, $unique_cols)){
    
                            $unique_values = [];
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
                array_push($exists_rows, $row);

            }

            $this->db_data[$table_name]["rows"] = $exists_rows;
            
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
            $rows = [];

            if($where_size === 0){

                if($cols === "*"){
                    $rows = $exists_rows;
                }else{
    
                    foreach($exists_rows as $row_idx => $row){
    
                        $temp_row = [];
                        foreach(explode(",", $cols) as $col_name){
                            if(isset($row[$col_name])) $temp_row[$col_name] = $row[$col_name];
                        }
                        array_push($rows, $temp_row);
    
                    }
    
                }

            }else if($where_size === 3){

                $rows = $this->where($where, $table_name, $cols);

                $rearranged_rows = [];
                foreach($rows as $row) array_push($rearranged_rows, $row);

                $rows = $rearranged_rows;

            }else throw new \Error("Size of where clause is not equal to 3 for '$table_name' table");

            return $rows;

        }

        // get available table form jsondb
        public function available_table(): array{

            if(!is_file($this->db_name)) throw new \Error("'$this->db_name' jsondb does not exists");

            $table = array_keys($this->db_data);
            
            return $table;

        }

        // get jsondb size in bytes
        public function db_size(): int{

            return filesize($this->db_name);

        }

    }

    trait update{

        // update values of row with respect to column, sets is a collection of column names and their values
        public function update_table(string $table_name, array $sets, array $where = []): int{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $exists_rows = $this->db_data[$table_name]["rows"];
            $rows = $this->where($where, $table_name, "*");

            // when table is empty
            if(count($rows) === 0) return 1;

            $dr_uc_nnc_aic = $this->dr_uc_nnc_aic($table_name);
            $unique_cols = $dr_uc_nnc_aic["unique_cols"];
            $not_null_cols = $dr_uc_nnc_aic["not_null_cols"];
            $auto_increment_cols = $dr_uc_nnc_aic["auto_increment_cols"];

            $col_name_datatype = $this->get_col_name_with_dt($table_name);
            $valid_columns = array_keys($col_name_datatype);

            foreach($sets as $col_name => $col_value){

                if(!in_array($col_name, $valid_columns))
                throw new \Error("'$col_name' column does not exists in '$table_name' table");

                $datatype = $col_name_datatype[$col_name];
                $val_datatype = $this->check_datatype($col_value);

                if($col_value !== null && $datatype !== $val_datatype)
                throw new \Error("Wrong datatype of '$col_name' column's value of '$table_name' table");

                if($val_datatype === "string"){ 
                    $val_len = strlen($col_value);
                    if(!($val_len >= $this->db_limits["datatype"]["string"]["min"] && $val_len <= $this->db_limits["datatype"]["string"]["max"]))
                    throw new \Error("Length of value of '$col_name' column into '$table_name' tables is out of limits");
                }
                
                if(in_array($col_name, $not_null_cols)){
                    if($col_value === null)
                    throw new \Error("Datatype of '$col_name' column's value can not be null");
                }

                if(in_array($col_name, $unique_cols)){

                    if(count($rows) !== 1)
                    throw new \Error("'$col_name' column is unique of '$table_name' table");

                    $unique_value = [];
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

            foreach($rows as $row_idx => $r){
                $exists_rows[$row_idx] = $r;
            }

            $this->db_data[$table_name]["rows"] = $exists_rows;
            
            return $this->write_into_db();

        }

        // alter table => add or remove column or columns
        public function alter_table(string $table_name, string $operation, $col, bool $bulk = false): int{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            switch($operation){
                case "ADD COLUMN": {

                    if($bulk){ 

                        if($this->check_datatype($col) !== "array")
                        throw new \Error("Datatype of 'col' paramter is not indexed array for add multiple columns at a time into '$table_name' table");
        
                        $columns = $col;
        
                    }else{
                        
                        if($this->check_datatype($col) !== "object")
                        throw new \Error("Datatype of 'col' paramter is not associative array for add single column at a time into '$table_name' table");
        
                        $columns = array( $col );
        
                    }
        
                    $exists_columns = $this->db_data[$table_name]["structure"]["columns"];
                    $num_of_cols = count($exists_columns) + count($columns);
                    if(!($num_of_cols >= $this->db_limits["table_limits"]["cols"]["min"] && $num_of_cols <= $this->db_limits["table_limits"]["cols"]["max"]))
                    throw new \Error("Number of columns into '$table_name' table are out of limits into '$this->db_name' jsondb");        
                    
                    $this->add_column($table_name, $columns);
                    
                } break;

                case "DROP COLUMN": {

                    if($bulk){ 

                        if($this->check_datatype($col) !== "array")
                        throw new \Error("Datatype of 'col' paramter is not indexed array for remove multiple columns at a time from '$table_name' table");
        
                        $columns = $col;
        
                    }else{
                        
                        if($this->check_datatype($col) !== "string")
                        throw new \Error("Datatype of 'col' paramter is not string for remove single column at a time from '$table_name' table");
        
                        $columns = array( $col );
        
                    }
                    
                    $this->remove_column($table_name, $columns); 
                    
                } break;

                default: throw new \Error("Invalid operation for alter a '$table_name' table");
            }

            return $this->write_into_db();

        }

    }

    trait delete{

        // delete the jsondb
        public function drop_db(): int{

            if(isset($this->db_name)){

                unlink($this->db_name);
                return 1;

            }else return 0;

        }

        // delete row/rows from table
        public function delete_from(string $table_name, array $where = []): int{

            if(count($where) === 0) return $this->truncate_table($table_name);

            $rows = $this->where($where, $table_name, "*");
            
            if(count($rows) === 0) return 1;
            
            $exists_rows = $this->db_data[$table_name]["rows"];
            
            foreach($rows as $row_idx => $r){
                unset($exists_rows[$row_idx]);
            }
            
            $new_rows = [];
            foreach($exists_rows as $er) array_push($new_rows, $er);

            $this->db_data[$table_name]["rows"] = $new_rows;

            return $this->write_into_db();

        }

        // delete table from jsondb
        public function drop_table(string $table_name): int{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            unset($this->db_data[$table_name]);

            return $this->write_into_db();

        }

        // delete all rows from table of jsondb
        public function truncate_table(string $table_name): int{

            if(!isset($this->db_data[$table_name]))
            throw new \Error("'$table_name' table does not exists in '$this->db_name' jsondb");

            $this->db_data[$table_name]["rows"] = [];

            return $this->write_into_db();

        }
    }

    class JsonDB{

        use private_task, create, read, update, delete;

        private
        $db_name, $db_data, $db_data_clone,
        $valid_datatype = [ "string", "number", "boolean", "object", "array", "null" ],
        $column_properties = [ "name", "datatype", "default", "unique", "not_null", "auto_increment" ],
        $where_clause_operators = [ "=", ">", "<", ">=", "<=", "!=" ],
        $db_limits = [
            "db_size" => [ "min"=> 2, "max"=> 10*1024*1024 ], // max = 10MB
            "num_of_table"=> [ "min"=> 0, "max"=> 4 ],
            "table_limits"=> [
                "rows"=> [ "min"=> 0, "max"=> 1024 ],
                "cols"=> [ "min"=> 1, "max"=> 1024 ],
                "col_name"=> [ "min"=> 1, "max"=> 32 ],
            ],
            "datatype"=> [
                "string"=> [ "min"=>0, "max"=> 2048 ]
            ]
        ];

        function __construct(string $db_name = ""){

            if($db_name !== ""){

                $this->connect_db($db_name);

            }

        }

        // connect with the jsondb
        public function connect_db(string $db_name){

            if(is_file($db_name)){

                $db_size = filesize($db_name);
                if(!($db_size >= $this->db_limits["db_size"]["min"] && $db_size <= $this->db_limits["db_size"]["max"]))
                throw new \Error("'$db_name' jsondb's size is out limits");

                $this->db_name = $db_name;

                $json = file_get_contents($db_name);
                $this->db_data = json_decode($json, true);
                $this->db_data_clone = $this->db_data;

            }else throw new \Error("'$db_name' jsondb does not exists");

        }

    }


?>
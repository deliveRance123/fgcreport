import os
import sys
import json
import datetime
from decimal import Decimal
from app.database import SessionLocal
from sqlalchemy import text

class CustomJsonEncoder(json.JSONEncoder):
    def default(self, o):
        if isinstance(o, (datetime.date, datetime.datetime)):
            return o.isoformat()
        if isinstance(o, Decimal):
            return float(o)
        if isinstance(o, (bytes, bytearray)):
            return o.hex()
        return super().default(o)

def backup_database():
    timestamp = datetime.datetime.now().strftime("%Y%m%d_%H%M%S")
    backup_dir = "backups"
    os.makedirs(backup_dir, exist_ok=True)
    json_path = os.path.join(backup_dir, f"fgc_database_backup_{timestamp}.json")
    sql_path = os.path.join(backup_dir, f"fgc_database_backup_{timestamp}.sql")
    
    db = SessionLocal()
    try:
        tables_query = text("""
            SELECT table_name 
            FROM information_schema.tables 
            WHERE table_schema = 'public' AND table_type = 'BASE TABLE'
            ORDER BY table_name;
        """)
        tables = [row[0] for row in db.execute(tables_query).fetchall()]
        
        backup_data = {
            "created_at": datetime.datetime.now(datetime.timezone.utc).isoformat(),
            "tables": {}
        }
        
        sql_lines = [
            f"-- Foursquare Gospel Church Reports Database Backup",
            f"-- Generated: {datetime.datetime.now()}",
            f"-- Total Tables: {len(tables)}\n",
            "SET statement_timeout = 0;",
            "SET lock_timeout = 0;",
            "SET client_encoding = 'UTF8';",
            "SET standard_conforming_strings = on;\n"
        ]
        
        total_records = 0
        for table in tables:
            rows_query = text(f'SELECT * FROM "{table}";')
            res = db.execute(rows_query)
            rows = res.fetchall()
            keys = list(res.keys())
            
            table_rows = []
            sql_lines.append(f"\n-- Data for table: {table}")
            
            for r in rows:
                row_dict = {}
                sql_vals = []
                for k, v in zip(keys, r):
                    if isinstance(v, (datetime.date, datetime.datetime)):
                        row_dict[k] = v.isoformat()
                        sql_vals.append(f"'{v.isoformat()}'")
                    elif isinstance(v, Decimal):
                        row_dict[k] = float(v)
                        sql_vals.append(str(float(v)))
                    elif isinstance(v, (bytes, bytearray)):
                        row_dict[k] = v.hex()
                        sql_vals.append(f"decode('{v.hex()}', 'hex')")
                    elif v is None:
                        row_dict[k] = None
                        sql_vals.append("NULL")
                    elif isinstance(v, bool):
                        row_dict[k] = v
                        sql_vals.append("TRUE" if v else "FALSE")
                    elif isinstance(v, (int, float)):
                        row_dict[k] = v
                        sql_vals.append(str(v))
                    else:
                        val_str = str(v).replace("'", "''")
                        row_dict[k] = str(v)
                        sql_vals.append(f"'{val_str}'")
                        
                table_rows.append(row_dict)
                cols_str = ", ".join([f'"{k}"' for k in keys])
                vals_str = ", ".join(sql_vals)
                sql_lines.append(f'INSERT INTO "{table}" ({cols_str}) VALUES ({vals_str}) ON CONFLICT DO NOTHING;')
            
            backup_data["tables"][table] = table_rows
            total_records += len(table_rows)
            print(f"  [+] Table '{table}': {len(table_rows)} records backed up.")
            
        with open(json_path, "w", encoding="utf-8") as f:
            json.dump(backup_data, f, indent=2, ensure_ascii=False, cls=CustomJsonEncoder)
            
        with open(sql_path, "w", encoding="utf-8") as f:
            f.write("\n".join(sql_lines))
            
        print("\n=======================================================")
        print("[SUCCESS] FULL DATABASE BACKUP SUCCESSFULLY CREATED!")
        print("=======================================================")
        print(f"JSON Backup File: {os.path.abspath(json_path)}")
        print(f"SQL Dump File:    {os.path.abspath(sql_path)}")
        print(f"Summary:          {total_records} total records across {len(tables)} tables.")
        print("=======================================================\n")
        return json_path, sql_path
    finally:
        db.close()

if __name__ == "__main__":
    backup_database()


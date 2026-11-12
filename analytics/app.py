import os
import sqlite3

from flask import Flask, jsonify, request

# Absolute paths built from this file's location, so the app works wherever it is hosted.
BASE_DIR = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DATA_DIR = os.getenv("DATA_DIR") or os.path.join(BASE_DIR, "storage")
DB_PATH = os.path.join(DATA_DIR, "shop.db")
PORT = int(os.getenv("ANALYTICS_PORT", "8001"))

app = Flask(__name__)

# Only paid orders that were not cancelled count as sales.
SALES = "o.payment_status = 'Approved' and o.status != 'Cancelled'"


def query(sql):
    # Read-only connection: analytics can never change shop data.
    uri = "file:" + DB_PATH.replace("\\", "/") + "?mode=ro"
    con = sqlite3.connect(uri, uri=True, timeout=5)
    con.row_factory = sqlite3.Row
    try:
        return [dict(row) for row in con.execute(sql).fetchall()]
    finally:
        con.close()


def count(sql):
    return query(sql)[0]["n"]


@app.get("/api/analytics/summary")
def summary():
    row = query(f"""
        select count(*) as orders,
               coalesce(sum(o.total_cents), 0) as revenue_cents,
               count(distinct o.user_id) as customers
        from orders o where {SALES}""")[0]
    row["items_sold"] = count(f"""
        select coalesce(sum(i.quantity), 0) as n
        from order_items i join orders o on o.id = i.order_id where {SALES}""")
    row["pending_orders"] = count("select count(*) as n from orders where payment_status = 'Pending'")
    row["cancelled_orders"] = count("select count(*) as n from orders where status = 'Cancelled'")
    row["low_stock_products"] = count("select count(*) as n from products where active = 1 and stock <= 5")
    return jsonify(row)


@app.get("/api/analytics/top-products")
def top_products():
    return jsonify(query(f"""
        select p.name, sum(i.quantity) as units_sold,
               sum(i.quantity * i.unit_price_cents) as revenue_cents
        from order_items i
        join orders o on o.id = i.order_id
        join products p on p.id = i.product_id
        where {SALES}
        group by p.id order by units_sold desc limit 5"""))


@app.get("/api/analytics/revenue")
def revenue():
    # The bucket comes from this fixed list, never from the request text.
    buckets = {
        "day": "date(o.created_at)",
        "week": "strftime('%Y-W%W', o.created_at)",
        "month": "strftime('%Y-%m', o.created_at)",
    }
    bucket = buckets.get(request.args.get("period", "day"))
    if bucket is None:
        return jsonify(error="period must be day, week or month"), 400
    rows = query(f"""
        select {bucket} as label, sum(o.total_cents) as revenue_cents, count(*) as orders
        from orders o where {SALES}
        group by label order by label desc limit 12""")
    return jsonify(rows[::-1])


if __name__ == "__main__":
    # Only this machine can reach it. The PHP shop passes admin requests on to it.
    app.run(host="127.0.0.1", port=PORT)
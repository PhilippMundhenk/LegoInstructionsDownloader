#!/bin/bash
ID=$1
dir=$2
#ID=31099
locale="de-de"

echo "extracting API key..."
script="$(curl -s https://www.lego.com/$locale/service/buildinginstructions/$ID | grep "scripts.min.min" | sed 's/.*\(https:.*scripts\.min\.min.*\.js\).*/\1/')
key=$(curl -s https://www.lego.com/service/dist/scripts.min.min.7dd59f4a67695fc3aed36c5ae6b6f1f5c549486914037938f9a56c55484125e6.js | grep x-api-key | sed 's/.*{headers:{"x-api-key":"\(.*\)"}};.*/\1/')"
echo "retrieved API key: $key"

#TODO: check if directory exists.
echo "creating folder $ID & extracting data..."
mkdir -p $2/$ID
cd $2/$ID
data="$(curl -s -X POST -H "Accept: application/json, text/plain, */*" -H "Accept-Language: de,en-US;q=0.7,en;q=0.3" -H "Accept-Encoding: gzip, deflate, br, zstd" -H "Content-Type: application/json" -H "x-api-key: p0OKLXd8US1YsquudM1Ov9Ja7H91jhamak9EMrRB" -H "Content-Length: 268" -H "Origin: https://www.lego.com" -H "DNT: 1" -H "Sec-GPC: 1" -H "Connection: keep-alive" -H "Referer: https://www.lego.com/" -H "Sec-Fetch-Dest: empty" -H "Sec-Fetch-Mode: cors" -H "Sec-Fetch-Site: same-site" https://services.slingshot.lego.com/api/v4/lego_historic_product_read/_search -d "{\"_source\":[\"product_number\",\"locale.$locale\",\"locale.en-us\",\"market.de.skus.item_id\",\"market.us.skus.item_id\",\"availability\",\"themes\",\"product_versions\",\"assets\"],\"from\":0,\"size\":1,\"query\":{\"bool\":{\"must\":[{\"term\":{\"product_number\":\"$ID\"}}],\"should\":[],\"filter\":[]}}}" | gunzip | python -m json.tool)"
echo "$data" > data.json

name=$(cat data.json | grep "display_title" | tail -1 | sed "s/.*\"\(.*\)\",/\1/")
echo "extracted data for $ID ($name)"
cat data.json | grep .pdf | grep url | sed "s/.*\(https:.*pdf\).*/\1/" > files.txt
cat data.json | grep .png | grep url | sed "s/.*\(https:.*png\).*/\1/" >> files.txt
cat data.json | grep .jpg | grep url | sed "s/.*\(https:.*jpg\).*/\1/" >> files.txt
echo "downloading $(cat files.txt | wc -l) files..."
pids=()
i=0
while read p; do
  (
	curl --connect-timeout 5 \
    --max-time 10 \
    --retry 5 \
    --retry-delay 0 \
    --retry-max-time 40 \
	-s -O "$p"
	if [ $? -eq 0 ]; then
	   echo "completed $p"
	else
	   echo "failed $p"
	fi
  )&
  pids[${i}]=$!
  i=$((i+1))
done < files.txt
for pid in ${pids[*]}; do
    wait $pid
done
rm files.txt
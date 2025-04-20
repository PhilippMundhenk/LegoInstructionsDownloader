#!/bin/bash
ID=$1
dir=$2
#ID=31099
locale="de-de"

#TODO: check if directory exists.
echo "[$ID] creating folder $ID & extracting data..."
mkdir -p $2/$ID
cd $2/$ID
wget https://www.lego.com/$locale/service/building-instructions/$ID

cat $ID | grep -oP "https://www.lego.com/cdn/product-assets/product.bi.core.\w{3}/\d{7}.\w{3}" | sort -u > files.txt
cat $ID | grep -oP "https://www.lego.com/cdn/product-assets/product.img.pri.*?\"" | sed 's/.$//' | sort -u >> files.txt
cat $ID | grep -oP '{"name":".*?"' | sed 's/{"name":"//' | sed 's/"//' > name.txt
echo "[$ID] downloading $(cat files.txt | wc -l) files..."
pids=()
i=0
while read p; do
  (
	#echo "trying $p..."
	curl --connect-timeout 30 \
		 --retry 5 \
		 --retry-delay 5 \
		 --retry-max-time 40 \
		 -s -O "$p"
		 ret=$?
		 if [ $ret -ne 0 ]; then
			echo "[$ID] ERROR! Failed to fetch $p"
		 fi
  )&
pids[${i}]=$!
i=$((i+1))
done < files.txt
for pid in ${pids[*]}; do
    wait $pid
done
echo "[$ID] completed $name"
rm files.txt

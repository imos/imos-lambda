usage:
	@echo 'Usage: make package' >&2
.PHONY: usage

package: package-gcc package-exec

package-gcc: lambda/gcc lambda/gcc-min.tar.xz lambda/index.js
	mkdir -p output
	cd lambda; zip ../output/gcc.zip gcc gcc-min.tar.xz index.js

package-%: lambda/% lambda/index.js
	mkdir -p output
	cd lambda; zip ../output/$*.zip $* index.js

install-%: package-%
	aws lambda update-function-code --region=ap-northeast-1 --function-name=$* --zip-file=fileb://output/$*.zip
.PHONY: install-%

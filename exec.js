console.log('Loading function');
var fs = require('fs');
var exec = require('child_process').exec;

var aws = require('aws-sdk');
var s3 = new aws.S3({apiVersion: '2006-03-01'});

exports.handler = function(event, context) {
    var requestId = context.awsRequestId;
    var startTime = (new Date()).getTime();
    
    var getElapsedTime = function() {
        return (new Date()).getTime() - startTime;
    };

    var onError = function(error) {
        console.log("error code: " + error.code + " error: " + error);
        context.done(error, "lambda");
    };

    var run = function(file, command, callback) {
        if ("command" in event && event.command !== "") {
            command = event.command;
        }
        command = "export OBJECT='" + file + "'; " + command;
        exec(command, function(error, stdout, stderr) {
            if (!error) {
                callback();
            }
            context.succeed({
                "stdout": stdout,
                "stderr": stderr,
                "elapsed_time": getElapsedTime(),
                "error": error});
        });
    };

    if ("bucket" in event && event.bucket !== "") {
        console.log("Fetching: " + event.key);
        s3.getObject(
            {Bucket: event.bucket, Key: event.key},
            function(error, data) {
                if (error) {
                    onError(error);
                    return;
                }
                var file = "/tmp/" + requestId;
                try {
                    fs.writeFileSync(file, data.Body);
                    var command = "chmod +x " + file + "; " + file;
                    if ("args" in event) {
                        command += " " + event.args;
                    }
                    run(file, command, function() {
                        try {
                            fs.unlinkSync(file);
                        } catch (e) {
                            console.log("Unlink Error: " + e);
                            return;
                        }
                    });
                } catch (e) {
                    onError(e);
                }
            });
    } else {
        run("", "", function() {});
    }
};
